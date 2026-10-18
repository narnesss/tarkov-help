<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset, ContentReference};
use App\Services\{ContentFields, ContentLinks};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Проверяет ограниченную выборку связей и отсутствие SQL для некорректного ввода. */
final class ContentLinksTest extends TestCase
{
    use RefreshDatabase;

    private const SPEC = ['kind' => 'item', 'variant' => 'default', 'multiple' => true];

    private function references(): array
    {
        $rows = [];
        for ($i = 1; $i <= 200; $i++) {
            $rows[] = ['kind' => 'item', 'variant' => 'default', 'value' => 'item-'.$i,
                'label' => 'Предмет '.$i, 'search_text' => 'предмет '.$i];
        }
        DB::table('content_references')->insert($rows);
        return ContentReference::orderByDesc('id')->pluck('id', 'value')->all();
    }

    private function queries(\Closure $action, int $expected): mixed
    {
        $connection = DB::connection();$connection->enableQueryLog();$connection->flushQueryLog();
        try {
            $result = $action();
            $this->assertCount($expected, $connection->getQueryLog());
            return $result;
        } finally { $connection->disableQueryLog();$connection->flushQueryLog(); }
    }

    public function test_two_hundred_choices_use_one_query_and_preserve_order_and_metadata(): void
    {
        $ids = $this->references();$links = new ContentLinks;
        $original = array_map(static fn ($value) => ['id' => $value, 'custom' => 'Сохранить'], array_keys($ids));
        $this->assertSame(array_values($ids), $this->queries(fn () => $links->selected($original, self::SPEC), 1));
        $this->assertSame($original, $this->queries(fn () => $links->resolve(array_map('strval', array_values($ids)), $original, self::SPEC, 'items'), 1));
        $this->assertSame(array_keys($ids), $this->queries(fn () => $links->resolve(array_values($ids), [], self::SPEC, 'items'), 1));
    }

    public function test_only_visible_entries_resolve_reference_types_and_hidden_fields_do_not_shift_pages(): void
    {
        $document = ['cmsHidden' => 'metadata'];
        for ($i = 1; $i <= 1000; $i++) { $document['field'.$i] = str_pad(dechex($i), 24, '0', STR_PAD_LEFT); }
        $queries = fn () => app(ContentFields::class)->entries($document, '', 2);
        $entries = $this->queries($queries, ContentFields::PAGE_SIZE);
        $this->assertCount(60, $entries);$this->assertSame('/field61', $entries[0]['path']);
        $this->assertSame('/field120', $entries[59]['path']);
        $this->assertSame([], $this->queries(fn () => app(ContentFields::class)->entries($document, '', 18), 0));
    }

    public function test_empty_and_malformed_choices_do_not_reach_sql(): void
    {
        $links = new ContentLinks;
        $this->assertSame([], $this->queries(fn () => $links->resolve([], [], self::SPEC, 'items'), 0));
        $this->assertSame([], $this->queries(fn () => $links->selected([], self::SPEC), 0));
        foreach ([[1, []], [true], [1.5], ['1 OR 1=1'], [str_repeat('9', 40)], [1, '1'], range(1, 201)] as $input) {
            $this->queries(function () use ($links, $input): void {
                try { $links->resolve($input, [], self::SPEC, 'items');$this->fail('Некорректная связь принята'); }
                catch (ValidationException $error) { $this->assertArrayHasKey('items', $error->errors()); }
            }, 0);
        }
    }

    public function test_wrong_kind_variant_and_unknown_ids_cannot_be_substituted(): void
    {
        $ids = $this->references();$id = array_values($ids)[0];$links = new ContentLinks;
        foreach ([[...self::SPEC, 'kind' => 'quest'], [...self::SPEC, 'variant' => 'regular']] as $spec) {
            try { $links->resolve([$id], [], $spec, 'items');$this->fail('Чужая связь принята'); }
            catch (ValidationException $error) { $this->assertArrayHasKey('items', $error->errors()); }
        }
        $this->expectException(ValidationException::class);$links->resolve([PHP_INT_MAX], [], self::SPEC, 'items');
    }

    public function test_replacement_metadata_is_loaded_in_two_queries_across_catalogs(): void
    {
        $ids = [];$expected = [];
        foreach (['a', 'b'] as $group) {
            $items = [];
            for ($i = 0; $i < 100; $i++) { $items[] = ['id' => $group.'-'.$i, 'name' => 'Предмет '.$group.$i, 'iconLink' => '/'.$group.$i.'.png', 'extra' => 'Не копировать']; }
            $dataset = new ContentDataset(['path' => 'catalog-'.$group.'.json']);$dataset->setContent(['items' => $items]);
            ContentDataset::withoutEvents(fn () => $dataset->save());
            foreach ($items as $i => $item) {
                $reference = ContentReference::create(['kind' => 'item', 'variant' => 'default', 'value' => $item['id'], 'label' => $item['name'],
                    'search_text' => '', 'source_dataset_id' => $dataset->id, 'source_pointer' => '/items/'.$i]);
                $ids[] = $reference->id;$expected[] = array_intersect_key($item, array_flip(['id', 'name', 'iconLink']));
            }
        }
        $original = [['id' => 'old', 'name' => 'Прежнее имя', 'iconLink' => '/old.png', 'custom' => 'Прежнее поле']];
        $saved = $this->queries(fn () => (new ContentLinks)->resolve(array_reverse($ids), $original, self::SPEC, 'items'), 2);
        $this->assertSame(array_reverse($expected), $saved);
    }

    public function test_invalid_choice_is_rejected_before_opening_any_source_file(): void
    {
        $dataset = new ContentDataset(['path' => 'broken-source.json', 'payload' => '@file:'.str_repeat('0', 64)]);
        ContentDataset::withoutEvents(fn () => $dataset->save());
        $reference = ContentReference::create(['kind' => 'item', 'variant' => 'default', 'value' => 'valid', 'label' => 'Имя',
            'search_text' => '', 'source_dataset_id' => $dataset->id, 'source_pointer' => '/items/0']);
        $this->queries(function () use ($reference): void {
            try { (new ContentLinks)->resolve([$reference->id, PHP_INT_MAX], [['id' => 'old']], self::SPEC, 'items');$this->fail('Чужая ссылка принята'); }
            catch (ValidationException $error) { $this->assertArrayHasKey('items', $error->errors()); }
        }, 1);
    }

}
