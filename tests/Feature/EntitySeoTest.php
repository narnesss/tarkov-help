<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset, Quest};
use App\Services\EntitySeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Blade, DB};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Проверяет текущие источники публичного HTML без production-каталогов и реквизитов. */
final class EntitySeoTest extends TestCase
{
    use RefreshDatabase;

    private function dataset(string $path, array $content): ContentDataset
    {
        return ContentDataset::withoutEvents(fn () => ContentDataset::updateOrCreate(['path'=>$path],
            ['payload'=>base64_encode(gzencode(json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))]));
    }

    private function content(string $page, array $params = []): array
    { return app(EntitySeo::class)->content($page, $params); }

    private function fails(int $status, callable $action): void
    {
        try { $action(); $this->fail('Ожидался HTTP '.$status); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    public function test_item_reads_live_mode_specific_data_and_keeps_zero_and_literal_special_characters(): void
    {
        $name = 'Предмет <script>alert("x")</script> & "кавычки"';
        $this->dataset('guide/items-pve.json', ['items'=>[['id'=>'item-1', 'normalizedName'=>'item-slug', 'name'=>$name, 'description'=>'Описание & <текст>', 'weight'=>0, 'width'=>1, 'properties'=>['damage'=>0, 'caliber'=>'Caliber9x19']]]]);
        $this->dataset('guide/items-regular.json', ['items'=>[['id'=>'item-1', 'name'=>'Версия PVP', 'description'=>'Другое описание']]]);
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $result = $this->content('reference', ['item'=>'item-slug']);
            $this->assertSame($name, $result['title']); $this->assertSame('Описание & <текст>', $result['entity']['description']);
            $this->assertContains('Масса: 0 кг', $result['entity']['facts']); $this->assertContains('Урон: 0', $result['entity']['facts']);
            $this->assertSame(['item'=>'item-1', 'tab'=>'items'], $result['canonicalParams']);
            $html = Blade::render('{{ $entity["name"] }} {{ $entity["description"] }}', ['entity'=>$result['entity']]);
            $this->assertStringNotContainsString('<script>', $html); $this->assertStringContainsString('&lt;script&gt;', $html);
            foreach (DB::getQueryLog() as $query) { $this->assertMatchesRegularExpression('/^select\s/i', $query['query']); }
        } finally { DB::disableQueryLog(); }
        $this->assertSame('Версия PVP', $this->content('reference', ['item'=>'item-1', 'mode'=>'regular'])['title']);
        $this->dataset('guide/items-pve.json', ['items'=>[['id'=>'item-1', 'name'=>'Обновлённое имя']]]);
        $this->assertSame('Обновлённое имя', $this->content('reference', ['item'=>'item-1'])['title']);
        $this->dataset('guide/items-pve.json', ['items'=>[['id'=>'item-1', 'name'=>'Снятый предмет', 'cmsArchived'=>true]]]);
        $this->fails(404, fn () => $this->content('reference', ['item'=>'item-1']));
        $this->assertSame('Версия PVP', $this->content('reference', ['item'=>'item-1', 'mode'=>'regular'])['title']);
    }

    public function test_item_catalog_paginates_filters_ammo_and_handles_empty_unknown_and_invalid_parameters(): void
    {
        $items = [];
        for ($i = 0; $i < 101; $i++) { $items[] = ['id'=>'item-'.$i, 'name'=>'Предмет '.$i, 'properties'=>['propertiesType'=>$i === 100 ? 'ItemPropertiesAmmo' : 'ItemPropertiesWeapon']]; }
        $items[] = ['id'=>'archived', 'name'=>'Архивный', 'cmsArchived'=>true];
        $this->dataset('guide/items-regular.json', ['items'=>$items]);
        $first = $this->content('reference', ['tab'=>'items', 'mode'=>'regular']);
        $this->assertCount(100, $first['entity']['links']); $this->assertSame(101, $first['total']);
        $this->assertSame('/reference/items?mode=regular&page=2', $first['nextUrl']);
        $last = $this->content('reference', ['tab'=>'items', 'mode'=>'regular', 'page'=>2]);
        $this->assertCount(1, $last['entity']['links']); $this->assertSame('/reference/items?mode=regular', $last['previousUrl']);
        $this->assertNull($last['nextUrl']);
        $this->assertSame(1, $this->content('reference', ['tab'=>'ammo', 'mode'=>'regular'])['total']);
        $this->assertSame(0, $this->content('reference', ['tab'=>'items'])['total']);
        foreach ([['tab'=>'items', 'page'=>3, 'mode'=>'regular'], ['tab'=>'items', 'page'=>0], ['tab'=>[]], ['item'=>[]], ['item'=>'../secret'], ['mode'=>[]], ['tab'=>'missing']] as $params) { $this->fails(404, fn () => $this->content('reference', $params)); }
        $this->assertCount(10, $this->content('reference')['entity']['links']);
        $this->fails(404, fn () => $this->content('reference', ['page'=>2]));
    }

    public function test_boss_matches_id_and_slug_and_removes_archived_records_without_using_old_export(): void
    {
        $this->dataset('guide/bosses.json', ['profiles'=>[['id'=>'bossTest', 'slug'=>'test-boss', 'name'=>'Босс', 'description'=>'Поведение', 'healthTotal'=>0, 'health'=>[['bodyPart'=>'Голова', 'max'=>0]], 'canonicalMapSlugs'=>['customs']], ['id'=>'archived', 'name'=>'Старый', 'cmsArchived'=>true]]]);
        $this->dataset('raid/maps.json', [['id'=>'customs', 'name'=>'Таможня']]);
        $this->dataset('guide/reference-pve.json', ['maps'=>[['id'=>'customs-id', 'normalizedName'=>'customs', 'name'=>'Таможня', 'bosses'=>[['mob'=>'bossTest', 'spawnChance'=>0]]], ['normalizedName'=>'woods', 'name'=>'Скрытая', 'cmsArchived'=>true, 'bosses'=>[['mob'=>'bossTest', 'spawnChance'=>1]]]]]);
        $detail = $this->content('boss', ['boss'=>'bossTest']);
        $this->assertSame($detail, $this->content('boss', ['boss'=>'test-boss']));
        $this->assertSame(['boss'=>'test-boss'], $detail['canonicalParams']);
        $this->assertContains('Общее здоровье: 0', $detail['entity']['facts']);
        $this->assertContains('Таможня: шанс появления в снимке 0%', $detail['entity']['facts']);
        $this->assertSame('/raid/customs', $detail['entity']['links'][0]['url']);
        $this->assertSame(1, $this->content('boss')['total']);
        $this->dataset('guide/bosses.json', ['profiles'=>[]]);
        $this->fails(404, fn () => $this->content('boss', ['boss'=>'test-boss']));
        $this->assertSame(0, $this->content('boss')['total']);
    }

    public function test_raid_uses_mode_file_verified_exits_and_normalizes_short_alias(): void
    {
        $this->dataset('raid/maps.json', [['id'=>'the-lab', 'name'=>'Лаборатория', 'baseFloor'=>['id'=>'base', 'name'=>'Главный этаж']], ['id'=>'customs', 'name'=>'Скрытая', 'cmsArchived'=>true]]);
        $this->dataset('raid/pve/the-lab.json', ['mapId'=>'the-lab', 'mapInfo'=>['description'=>'Текущее описание', 'raidDuration'=>0, 'players'=>'1–6', 'minPlayerLevel'=>0], 'pois'=>[
            ['id'=>'exit', 'kind'=>'extract', 'name'=>'Лифт', 'conditions'=>['Включить питание']], ['id'=>'old', 'kind'=>'extract', 'name'=>'Старый', 'cmsArchived'=>true],
            ['id'=>'unverified', 'kind'=>'extract', 'name'=>'Не проверен', 'sourceStatus'=>'unverified-coordinates'], ['id'=>'hidden', 'kind'=>'extract', 'name'=>'Скрыт', 'hiddenByDefault'=>true],
        ]]);
        $this->dataset('raid/the-lab.json', ['mapId'=>'other-map', 'mapInfo'=>['description'=>'Чужие данные'], 'pois'=>[['kind'=>'extract', 'name'=>'Чужой выход']]]);
        $result = $this->content('raid', ['map'=>'lab']);
        $this->assertSame(['map'=>'the-lab'], $result['canonicalParams']);
        $this->assertSame('Текущее описание', $result['description']);
        $this->assertContains('Продолжительность рейда: 0 мин.', $result['entity']['facts']);
        $this->assertContains('Выход: Лифт — Включить питание', $result['entity']['facts']);
        $this->assertStringNotContainsString('Старый', json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Чуж', json_encode($this->content('raid', ['map'=>'lab', 'mode'=>'regular']), JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $this->content('raid')['total']);
        $this->fails(404, fn () => $this->content('raid', ['map'=>'customs']));
    }

    public function test_documents_count_only_current_matching_spawns_and_long_alias_resolves_to_document_id(): void
    {
        $maps = $this->dataset('maps.json', [['id'=>'streets', 'name'=>'Улицы Таркова']]);
        $this->dataset('spawns.json', [['id'=>'point', 'map'=>'streets', 'docName'=>'Архив', 'caption'=>'В здании'], ['map'=>'streets', 'caption'=>'Скрыт', 'cmsArchived'=>true], ['map'=>'customs', 'caption'=>'Другая карта']]);
        $result = $this->content('documents', ['map'=>'streets-of-tarkov']);
        $this->assertSame(['map'=>'streets'], $result['canonicalParams']);
        $this->assertSame(['Опубликованных мест документов: 1', 'Архив: В здании'], $result['entity']['facts']);
        $maps->delete(); $this->fails(404, fn () => $this->content('documents', ['map'=>'streets']));
        $this->assertSame(0, $this->content('documents')['total']);
    }

    public function test_reference_tabs_show_actual_records_recipes_and_btr_routes(): void
    {
        $this->dataset('guide/items-pve.json', ['items'=>[['id'=>'product', 'name'=>'Изделие']]]);
        $this->dataset('guide/reference-pve.json', ['crafts'=>[['id'=>'recipe', 'productItem'=>['item'=>'product', 'count'=>0], 'duration'=>0, 'level'=>0]], 'barters'=>[['id'=>'offer', 'offeredItem'=>['item'=>'product', 'count'=>2], 'minTraderLevel'=>0]], 'traders'=>[['id'=>'trader', 'name'=>'Торговец', 'description'=>'Описание', 'levels'=>[[], ['cmsArchived'=>true]]]], 'hideout'=>[['id'=>'module', 'name'=>'Модуль', 'levels'=>[]]], 'achievements'=>[['id'=>'achieve', 'name'=>'Достижение', 'description'=>'Условие']]]);
        $this->dataset('raid/maps.json', [['id'=>'woods', 'name'=>'Лес']]);
        $this->dataset('guide/btr.json', ['maps'=>['woods'=>['mapId'=>'woods', 'routes'=>[['id'=>'r1', 'label'=>'Маршрут 1'], ['id'=>'hidden', 'cmsArchived'=>true]]]]]);
        $this->assertContains('Изделие × 0 · производство: 0 с · уровень модуля: 0', $this->content('reference', ['tab'=>'crafts'])['entity']['facts']);
        $this->assertContains('Изделие × 2 · уровень торговца: 0', $this->content('reference', ['tab'=>'barters'])['entity']['facts']);
        $this->assertContains('Торговец: Описание · уровней: 1', $this->content('reference', ['tab'=>'traders'])['entity']['facts']);
        $this->assertContains('Модуль · уровней: 0', $this->content('reference', ['tab'=>'hideout'])['entity']['facts']);
        $this->assertContains('Достижение: Условие', $this->content('reference', ['tab'=>'achievements'])['entity']['facts']);
        $this->assertSame([['name'=>'Лес · Маршрут 1', 'url'=>'/raid/woods?category=btr']], $this->content('reference', ['tab'=>'btr'])['entity']['links']);
    }

    public function test_needed_items_use_only_active_published_quests_and_objectives_in_selected_mode(): void
    {
        $this->dataset('guide/items-pve.json', ['items'=>array_map(static fn ($id) => ['id'=>$id, 'name'=>$id], ['active', 'hidden', 'pvp', 'archived', 'supplementary'])]);
        foreach ([['active', true, [], [['item'=>'active'], ['item'=>'hidden', 'cmsArchived'=>true], ['item'=>'pvp', 'modes'=>['regular']]]], ['hidden', false, [], [['item'=>'hidden']]], ['archived', true, ['cmsArchived'=>true], [['item'=>'archived']]], ['supplementary', true, ['catalogSection'=>'supplementary'], [['item'=>'supplementary']]]] as [$id, $published, $extra, $objectives]) {
            $objectives = array_map(static fn ($goal) => ['type'=>'giveItem', ...$goal], $objectives);
            Quest::withoutEvents(fn () => Quest::create(['quest_id'=>$id, 'name'=>$id, 'mode'=>'pve', 'published'=>$published, 'payload'=>['id'=>$id, 'name'=>$id, 'objectives'=>$objectives, ...$extra]]));
        }
        $result = $this->content('reference', ['tab'=>'needed']);
        $this->assertSame(1, $result['total']); $this->assertSame('/reference/items/active', $result['entity']['links'][0]['url']);
        $this->assertSame($result, $this->content('reference', ['tab'=>'needed']));
        $quest = Quest::where('quest_id', 'active')->firstOrFail();
        Quest::withoutEvents(fn () => $quest->update(['payload'=>['id'=>'active', 'objectives'=>[['type'=>'findItem', 'item'=>'hidden']]]]));
        $this->assertSame('/reference/items/hidden', $this->content('reference', ['tab'=>'needed'])['entity']['links'][0]['url']);
    }

    public function test_long_content_is_bounded_and_excessive_scanning_refuses_partial_output(): void
    {
        $this->dataset('guide/items-pve.json', ['items'=>[['id'=>'long', 'name'=>str_repeat('Я', 5000), 'description'=>str_repeat('Описание ', 1000)]]]);
        $result = $this->content('reference', ['item'=>'long']);
        $this->assertLessThanOrEqual(4000, mb_strlen($result['title'])); $this->assertLessThanOrEqual(230, mb_strwidth($result['description']));
        $this->dataset('guide/items-pve.json', ['items'=>array_fill(0, 45001, ['id'=>'archived', 'cmsArchived'=>true])]);
        $this->fails(503, fn () => $this->content('reference', ['tab'=>'items']));
    }
}
