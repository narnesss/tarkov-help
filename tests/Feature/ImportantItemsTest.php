<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\ImportantItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Проверяет готовый HTML, пагинацию, безопасность поиска и обновление файловых версий. */
final class ImportantItemsTest extends TestCase
{
    use RefreshDatabase;
    private function seedCatalog(int $count = 27): void
    {
        $items = $goals = [];
        for ($n = 1; $n <= $count; $n++) {
            $id = sprintf('item-%02d', $n);
            $items[] = ['id' => $id, 'name' => sprintf('Предмет %02d', $n), 'iconLink' => '/safe.png'];
            $goals[] = ['id' => 'find-'.$n, 'type' => 'findItem', 'item' => $id, 'count' => 2, 'foundInRaid' => true];
            $goals[] = ['id' => 'give-'.$n, 'type' => 'giveItem', 'item' => $id, 'count' => 2, 'foundInRaid' => true];
        }
        foreach (['pve', 'regular'] as $mode) {
            foreach (['items' => ['items' => $items], 'reference' => ['hideout' => [], 'crafts' => []], 'quests' => ['tasks' => []]] as $kind => $data) {
                DB::table('content_datasets')->insert(['path' => "guide/$kind-$mode.json", 'payload' => base64_encode(gzencode(json_encode($data)))]);
            }
            DB::table('quests')->insert(['quest_id' => 'stable-quest', 'mode' => $mode, 'name' => 'Задание', 'published' => true,
                'payload' => json_encode(['id' => 'stable-quest', 'name' => 'Задание', 'objectives' => $goals]) ]);
        }
    }

    public function test_html_is_paginated_before_delivery_and_preserves_filters(): void
    {
        $this->seedCatalog();
        $first = $this->get('/important-items?mode=pve&kind=quest&fir=fir')->assertOk()->assertSee('Предмет 01')->assertDontSee('Предмет 27');
        $this->assertSame(24, substr_count($first->getContent(), '<article class="important-card">'));
        $first->assertSee('kind=quest&amp;fir=fir&amp;page=2', false);
        $this->get('/important-items?mode=pve&page=2&kind=quest&fir=fir')->assertOk()->assertSee('Предмет 27')->assertDontSee('Предмет 01');
        $this->get('/important-items?mode=regular&page=2')->assertOk()->assertSee('Предмет 27');
        $this->get('/important-items?page=3')->assertNotFound();
        $this->assertFalse($first->headers->has('Set-Cookie'));
        $first->assertSee('method="get"', false)->assertSee('loading="lazy"', false);
    }

    public function test_search_is_trimmed_literal_escaped_and_validated(): void
    {
        $this->seedCatalog();
        $this->get('/important-items?q=%20%20Предмет%2001%20%20')->assertOk()->assertSee('Найдено: 1')->assertSee('value="Предмет 01"', false);
        foreach (["' OR 1=1 --", '"><script>alert(1)</script>'] as $query) {
            $response = $this->get('/important-items?'.http_build_query(['q' => $query]))->assertOk()->assertSee('Найдено: 0');
            $response->assertDontSee('<script>alert(1)</script>', false);
        }
        foreach ([['q' => ['bad']], ['mode' => 'bad'], ['page' => 0], ['kind' => ['quest']], ['fir' => 'bad'], ['q' => str_repeat('x', 161)], ['q' => "bad\x01"]] as $query) {
            $this->getJson('/api/content/important-items?'.http_build_query($query))->assertStatus(422);
        }
        $this->assertSame(2, DB::table('quests')->count());
    }

    public function test_cache_is_invalidated_by_quest_and_dataset_revisions(): void
    {
        $this->seedCatalog(1);
        $this->get('/important-items')->assertSee('Задание');
        DB::table('quests')->where('mode', 'pve')->update(['name' => 'Обновлённое задание']);
        $this->get('/important-items')->assertSee('Обновлённое задание');
        DB::table('content_datasets')->where('path', 'guide/items-pve.json')->update(['payload' => base64_encode(gzencode(json_encode(['items' => [['id' => 'item-01', 'name' => 'Новое имя', 'iconLink' => 'javascript:alert(1)']]])))]);
        $this->get('/important-items')->assertSee('Новое имя')->assertDontSee('javascript:alert(1)', false);
        DB::table('quests')->where('mode', 'pve')->update(['published' => false]);
        $this->get('/important-items')->assertSee('Найдено: 0');
    }

    public function test_static_export_contains_no_catalog_and_missing_backend_never_reads_a_snapshot(): void
    {
        $html = app(\App\Services\PageViews::class)->render('important-items', 'static');
        $this->assertStringNotContainsString('important-card', $html);
        $this->getJson('/api/content/important-items')->assertStatus(503);
    }

    public function test_aggregate_deduplicates_delivery_and_retains_alternatives_and_hideout(): void
    {
        $rows = ImportantItems::aggregate([['id' => 'q', 'name' => 'Квест', 'objectives' => [
            ['type' => 'findItem', 'item' => 'a', 'count' => 3, 'foundInRaid' => true], ['type' => 'giveItem', 'item' => 'a', 'count' => 3],
            ['type' => 'giveItem', 'items' => ['a', 'b'], 'count' => 1, 'optional' => true],
        ]]], ['hideout' => [['name' => 'Верстак', 'levels' => [['id' => 'station-1', 'level' => 1, 'itemRequirements' => [['item' => 'a', 'count' => 2]]]]]],
            'crafts' => [['productItem' => ['item' => 'a']]]], ['items' => [['id' => 'a', 'name' => 'А'], ['id' => 'b', 'name' => 'Б']]]);
        $this->assertCount(2, $rows);
        $this->assertSame(3.0, $rows[0]['uses'][0]['count']);
        $this->assertTrue($rows[0]['uses'][0]['foundInRaid']);
        $this->assertFalse($rows[0]['uses'][0]['alternative']);
        $this->assertFalse($rows[0]['uses'][0]['optional']);
        $this->assertTrue($rows[1]['uses'][0]['alternative']);
        $this->assertSame('hideout', $rows[0]['uses'][1]['kind']);
        $this->assertTrue($rows[0]['craftable']);
    }
}
