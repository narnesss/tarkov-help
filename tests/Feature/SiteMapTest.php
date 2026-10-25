<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset, Quest};
use App\Services\{EntitySeo, PageSeo, SiteMap};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Sitemap следует текущим публикациям и отказывает целиком при превышении бюджета. */
final class SiteMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    { parent::setUp(); config(['site.url'=>'https://wiki.test']); }

    private function dataset(string $path, array $content): ContentDataset
    {
        return ContentDataset::withoutEvents(fn () => ContentDataset::updateOrCreate(['path'=>$path],
            ['payload'=>base64_encode(gzencode(json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))]));
    }

    private function quest(string $id, string $trader, string $mode = 'pve', array $flags = [], bool $published = true): Quest
    {
        return Quest::withoutEvents(fn () => Quest::create(['quest_id'=>$id, 'mode'=>$mode, 'name'=>'Тест', 'trader_id'=>$trader,
            'published'=>$published, 'payload'=>['id'=>$id, 'name'=>'Тест', ...$flags]]));
    }

    private function locations(string $path = '/sitemaps/pages.xml', bool $static = false): array
    {
        $xml = simplexml_load_string(app(SiteMap::class)->xml($path, $static));
        $this->assertNotFalse($xml);
        return array_map('strval', $xml->xpath('//*[local-name()="loc"]'));
    }

    public function test_live_entities_keep_item_modes_separate_and_remove_archived_or_deleted_records_immediately(): void
    {
        $index = json_decode(file_get_contents(public_path('data/page-routes.json')), true, 64, JSON_THROW_ON_ERROR);
        [$active, $archived, $pvpOnly] = array_slice(array_keys($index['items']), 0, 3);
        $this->dataset('guide/items-pve.json', ['items'=>[['id'=>$active], ['id'=>$archived, 'cmsArchived'=>true]]]);
        $this->dataset('guide/items-regular.json', ['items'=>[['id'=>$pvpOnly], ['id'=>$active, 'cmsArchived'=>true]]]);
        $this->dataset('guide/bosses.json', ['profiles'=>[['id'=>'bossBully', 'slug'=>'reshala'], ['id'=>'bossKilla', 'slug'=>'killa', 'cmsArchived'=>true]]]);
        $this->dataset('raid/maps.json', [['id'=>'woods'], ['id'=>'shoreline', 'cmsArchived'=>true]]);
        $documents = $this->dataset('maps.json', [['id'=>'customs'], ['id'=>'reserve', 'cmsArchived'=>true]]);
        $locations = $this->locations();
        foreach (['/boss/reshala', '/raid/woods', '/documents/customs'] as $path) {
            $this->assertContains('https://wiki.test'.$path, $locations);
        }
        foreach (['/reference/items/'.$active, '/reference/items/'.$archived, '/reference/items/'.$pvpOnly, '/boss/killa', '/raid/shoreline', '/documents/reserve'] as $path) {
            $this->assertNotContains('https://wiki.test'.$path, $locations);
        }
        $this->assertSame(['https://wiki.test/reference/items/'.$active], $this->locations('/sitemaps/items-pve.xml'));
        $this->assertSame(['https://wiki.test/reference/items/'.$pvpOnly.'?mode=regular'], $this->locations('/sitemaps/items-regular.xml'));
        try {
            app(EntitySeo::class)->content('reference', ['item'=>$pvpOnly, 'mode'=>'pve']);
            $this->fail('Предмет только PVP не должен открываться в PVE.');
        } catch (HttpExceptionInterface $error) { $this->assertSame(404, $error->getStatusCode()); }
        $this->assertSame('/reference/items/'.$pvpOnly, app(EntitySeo::class)->content('reference', ['item'=>$pvpOnly, 'mode'=>'regular'])['entity']['url']);
        $this->assertSame(count($locations), count(array_unique($locations)));
        $this->dataset('guide/items-pve.json', ['items'=>[['id'=>$active, 'cmsArchived'=>true]]]);
        $this->dataset('guide/bosses.json', ['profiles'=>[]]);
        $documents->delete();
        $locations = $this->locations();
        $this->assertNotContains('https://wiki.test/reference/items/'.$active, $locations);
        $this->assertNotContains('https://wiki.test/boss/reshala', $locations);
        $this->assertNotContains('https://wiki.test/documents/customs', $locations);
        $this->assertSame([], $this->locations('/sitemaps/items-pve.xml'));
        $this->assertSame(['https://wiki.test/reference/items/'.$pvpOnly.'?mode=regular'], $this->locations('/sitemaps/items-regular.xml'));
    }

    public function test_new_live_entities_do_not_require_an_exported_route_and_use_the_same_canonical_as_html(): void
    {
        $this->dataset('guide/items-pve.json', ['items'=>[['id'=>'new-item-pve', 'name'=>'Новый предмет PVE']]]);
        $this->dataset('guide/items-regular.json', ['items'=>[['id'=>'new-item-pvp', 'name'=>'Новый предмет PVP']]]);
        $this->dataset('guide/bosses.json', ['profiles'=>[['id'=>'new-boss-id', 'slug'=>'new-boss-slug', 'name'=>'Новый босс']]]);
        $this->dataset('raid/maps.json', [['id'=>'new-raid-map', 'name'=>'Новая карта']]);
        $this->dataset('maps.json', [['id'=>'new-document-map', 'name'=>'Новые документы']]);
        $catalog = array_fill_keys(['quests', 'traders', 'maps', 'documents', 'bosses', 'items', 'sourceMaps'], []);
        $this->temporaryCatalog($catalog, function (): void {
            $pages = $this->locations();
            foreach ([['boss', ['boss'=>'new-boss-id'], '/boss/new-boss-slug'], ['raid', ['map'=>'new-raid-map'], '/raid/new-raid-map'], ['documents', ['map'=>'new-document-map'], '/documents/new-document-map']] as [$template, $params, $path]) {
                $content = app(EntitySeo::class)->content($template, $params);
                $meta = app(PageSeo::class)->metadata($template, $content['canonicalParams'], $content['title']);
                $this->assertSame('https://wiki.test'.$path, $meta['canonical']);
                $this->assertContains($meta['canonical'], $pages);
            }
            $this->assertNotContains('https://wiki.test/boss/new-boss-id', $pages);
            foreach (['pve'=>'new-item-pve', 'regular'=>'new-item-pvp'] as $mode=>$id) {
                $content = app(EntitySeo::class)->content('reference', ['item'=>$id, 'mode'=>$mode]);
                $meta = app(PageSeo::class)->metadata('reference', [...$content['canonicalParams'], 'mode'=>$mode], $content['title']);
                $this->assertSame([$meta['canonical']], $this->locations('/sitemaps/items-'.$mode.'.xml'));
                $this->assertNotContains($meta['canonical'], $pages);
            }
        });
    }

    public function test_missing_datasets_keep_sections_but_do_not_advertise_entity_pages(): void
    {
        $locations = $this->locations();
        foreach (['/', '/reference/items', '/boss', '/raid', '/documents', '/quests', '/quests/all', '/quests/story', '/quests/archive'] as $path) {
            $this->assertContains('https://wiki.test'.$path, $locations);
        }
        foreach ($locations as $location) {
            $this->assertDoesNotMatchRegularExpression('~/(?:reference/items/|boss/|raid/|documents/|quests/trader/)~', $location);
            $this->assertDoesNotMatchRegularExpression('~/(?:account|admin|api|404|quest-map-source)(?:[/.?]|$)~', $location);
        }
        $this->assertSame([], $this->locations('/sitemaps/items-pve.xml'));
        $this->assertSame([], $this->locations('/sitemaps/items-regular.xml'));
    }

    public function test_raid_dataset_aliases_publish_canonical_routes_and_recheck_archiving(): void
    {
        $aliases = ['streets-of-tarkov'=>'streets', 'the-lab'=>'lab', 'ground-zero'=>'groundzero'];
        $this->dataset('raid/maps.json', array_map(static fn ($id) => ['id'=>$id], array_keys($aliases)));
        $locations = $this->locations();
        foreach ($aliases as $source=>$canonical) {
            $this->assertContains('https://wiki.test/raid/'.$source, $locations);
            $this->assertNotContains('https://wiki.test/raid/'.$canonical, $locations);
        }
        // Даже старый снимок маршрутов с короткими ID не меняет канонические адреса живого HTML.
        $catalog = array_fill_keys(['quests', 'traders', 'maps', 'documents', 'bosses', 'items', 'sourceMaps'], []);
        foreach ($aliases as $canonical) { $catalog['maps'][$canonical] = ['name'=>'Карта '.$canonical]; }
        $this->temporaryCatalog($catalog, function () use ($aliases): void {
            $locations = $this->locations();
            foreach ($aliases as $source=>$alias) {
                $content = app(EntitySeo::class)->content('raid', ['map'=>$alias]);
                $this->assertSame('/raid/'.$source, $content['entity']['url']);
                $this->assertContains('https://wiki.test/raid/'.$source, $locations);
                $this->assertNotContains('https://wiki.test/raid/'.$alias, $locations);
            }
            $this->dataset('raid/maps.json', [['id'=>'streets-of-tarkov'], ['id'=>'the-lab'], ['id'=>'ground-zero', 'cmsArchived'=>true]]);
            $locations = $this->locations();
            $this->assertContains('https://wiki.test/raid/streets-of-tarkov', $locations);
            $this->assertContains('https://wiki.test/raid/the-lab', $locations);
            $this->assertNotContains('https://wiki.test/raid/ground-zero', $locations);
        });
    }

    public function test_trader_urls_follow_active_published_quests_in_each_mode_including_new_traders(): void
    {
        $prapor = '54cb50c76803fa8b248b4571';
        $this->quest('prapor-pve', $prapor); $this->quest('prapor-pvp', $prapor, 'regular');
        $this->quest('new', 'new-trader');
        $this->quest('hidden', 'hidden-trader', published:false);
        $this->quest('archived', 'archived-trader', flags:['cmsArchived'=>true]);
        $this->quest('story', 'story-trader', flags:['story'=>true]);
        $this->quest('supplementary', 'archive-trader', flags:['catalogSection'=>'supplementary']);
        $locations = $this->locations();
        foreach (['/quests/trader/prapor', '/quests/trader/prapor?mode=regular', '/quests/trader/new-trader'] as $path) {
            $this->assertContains('https://wiki.test'.$path, $locations);
        }
        foreach (['hidden-trader', 'archived-trader', 'story-trader', 'archive-trader', 'therapist'] as $trader) {
            $this->assertNotContains('https://wiki.test/quests/trader/'.$trader, $locations);
        }
    }

    public function test_static_export_never_reads_database_and_index_does_not_link_a_missing_quest_file(): void
    {
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $locations = $this->locations('/sitemaps/pages.xml', true);
            $this->assertContains('https://wiki.test/boss/reshala', $locations);
            $this->assertSame(['https://wiki.test/sitemaps/pages.xml'], $this->locations('/sitemap.xml', true));
            foreach (['/sitemaps/quests.xml', '/sitemaps/items-pve.xml', '/sitemaps/items-regular.xml'] as $path) {
                $this->assertUnavailable(fn () => app(SiteMap::class)->xml($path, true));
            }
            $this->assertSame([], DB::getQueryLog());
        } finally { DB::disableQueryLog(); }
    }

    public function test_large_xml_fails_with_503_instead_of_returning_a_partial_map(): void
    {
        $catalog = array_fill_keys(['quests', 'traders', 'maps', 'documents', 'bosses', 'items', 'sourceMaps'], []);
        for ($i = 0; $i < 9000; $i++) { $catalog['items'][str_pad((string) $i, 128, 'a', STR_PAD_LEFT)] = ['name'=>'Предмет']; }
        $this->temporaryCatalog($catalog, function (): void { $this->assertUnavailable(fn () => app(SiteMap::class)->xml('/sitemaps/pages.xml', true)); });
        $this->dataset('guide/items-regular.json', ['items'=>array_map(static fn ($id) => ['id'=>$id], array_keys($catalog['items']))]);
        $this->assertUnavailable(fn () => app(SiteMap::class)->xml('/sitemaps/items-regular.xml'));
    }

    public function test_scanned_record_limit_includes_archived_rows_without_output_urls(): void
    {
        $this->dataset('guide/items-pve.json', ['items'=>array_fill(0, 45001, ['id'=>'archived', 'cmsArchived'=>true])]);
        $this->assertUnavailable(fn () => app(SiteMap::class)->xml('/sitemaps/items-pve.xml'));
    }

    public function test_pages_scan_budget_is_shared_across_datasets_and_applies_on_cache_hits(): void
    {
        $rows = array_fill(0, 15001, ['id'=>'archived', 'cmsArchived'=>true]);
        $this->dataset('guide/bosses.json', ['profiles'=>$rows]);
        $this->dataset('raid/maps.json', $rows);
        $this->dataset('maps.json', $rows);
        $this->assertUnavailable(fn () => app(SiteMap::class)->xml('/sitemaps/pages.xml'));
        $this->assertUnavailable(fn () => app(SiteMap::class)->xml('/sitemaps/pages.xml'));
    }

    private function assertUnavailable(callable $action): void
    {
        try { $action(); $this->fail('Ожидался отказ вместо частичного sitemap.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame(503, $error->getStatusCode()); }
    }

    private function temporaryCatalog(array $catalog, callable $action): void
    {
        $directory = sys_get_temp_dir().'/wiki-sitemap-test-'.bin2hex(random_bytes(8));
        mkdir($directory.'/data', 0700, true); $original = public_path(); $file = $directory.'/data/page-routes.json';
        try {
            file_put_contents($file, json_encode($catalog, JSON_THROW_ON_ERROR)); $this->app->usePublicPath($directory); $action();
        } finally { $this->app->usePublicPath($original); unlink($file); rmdir($directory.'/data'); rmdir($directory); }
    }
}
