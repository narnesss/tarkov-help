<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\{PageCatalog, PageSeo};
use Tests\TestCase;

/** Метаданные отражают страницу, не зависят от cookie и безопасны в HTML/JSON. */
final class PageSeoTest extends TestCase
{
    public function test_all_catalog_routes_have_entity_metadata_and_absolute_canonical(): void
    {
        config(['site.url'=>'https://wiki.test']);
        $seo = app(PageSeo::class);
        foreach (app(PageCatalog::class)->routes() as $path => $route) {
            $meta = $seo->metadata($route['template'], $route['params'], $route['title']);
            $this->assertStringStartsWith('https://wiki.test/', $meta['canonical'], $path);
            $this->assertNotSame(config('pages.'.$route['template'].'.title'), $meta['title'], $path);
            $this->assertNotSame('', $meta['description'], $path);
            $this->assertStringNotContainsString('.html', $meta['canonical'], $path);
            $this->assertStringNotContainsString('localhost', $meta['canonical'], $path);
        }
    }

    public function test_quest_aliases_filters_and_modes_have_consistent_canonical(): void
    {
        config(['site.url'=>'https://wiki.test/']);
        $seo = app(PageSeo::class);
        $id = '5936d90786f7742b1420ba5b';
        $meta = $seo->metadata('quest', ['quest'=>$id, 'q'=>'test', 'flag'=>'kappa']);
        $this->assertSame('https://wiki.test/quests/debut', $meta['canonical']);
        $this->assertSame($meta['canonical'], $seo->metadata('quest', ['quest'=>'debut', 'mode'=>'pve'])['canonical']);
        $pvp = $seo->metadata('quest', ['quest'=>$id, 'mode'=>'regular']);
        $this->assertSame($meta['canonical'].'?mode=regular', $pvp['canonical']);
        $this->assertStringContainsString('PVP', $pvp['title']);
        $trader = $seo->metadata('quests', ['trader'=>'54cb50c76803fa8b248b4571']);
        $this->assertSame('https://wiki.test/quests/trader/prapor', $trader['canonical']);
        $this->assertStringContainsString('Прапор', $trader['description']);
        $paged = $seo->metadata('quests', ['trader'=>'prapor', 'mode'=>'regular', 'page'=>'2']);
        $this->assertSame($trader['canonical'].'?mode=regular&page=2', $paged['canonical']);
        $this->assertStringContainsString('страница 2', $paged['title']);
        $this->assertStringContainsString('PVP', $paged['title']);
    }

    public function test_private_pages_empty_shells_and_embedded_maps_are_not_indexed(): void
    {
        $seo = app(PageSeo::class);
        foreach ([['account', []], ['not-found', []], ['quest-map-source', ['map'=>'customs']], ['quest', []], ['boss', ['boss'=>'']], ['reference', ['item'=>'']]] as [$page, $params]) {
            $meta = $seo->metadata($page, $params);
            $this->assertStringStartsWith('noindex', $meta['robots']);
            $this->assertSame([], $meta['schema']);
        }
        $this->assertNull($seo->metadata('not-found')['canonical']);
        $this->assertSame('noindex, nofollow', $seo->metadata('account')['robots']);
        $this->assertSame('index, follow', $seo->metadata('reference', ['tab'=>'items'])['robots']);
    }

    public function test_origin_and_route_inputs_cannot_inject_a_canonical_url(): void
    {
        $seo = app(PageSeo::class);
        foreach (['http://wiki.test', 'https://user:secret@wiki.test', 'https://wiki.test/path', 'https://wiki.test?q=1', ['invalid']] as $origin) {
            config(['site.url'=>$origin]);
            $this->assertSame('https://tarkov-compass.narnesss.workers.dev/', $seo->metadata('index')['canonical']);
        }
        foreach (['//evil.test', '../other', 'test" onclick="evil', [], null, 0] as $id) {
            $meta = $seo->metadata('quest', ['quest'=>$id]);
            $this->assertSame('noindex, follow', $meta['robots']);
            $this->assertStringNotContainsString('evil', $meta['canonical']);
        }
    }

    public function test_head_escapes_literal_text_and_structured_data(): void
    {
        $name = 'Квест "Ноль" </script><script>alert(1)</script> & SQL \' OR 1=1';
        $seo = app(PageSeo::class)->metadata('quest', ['quest'=>'new-quest'], $name);
        $html = view('components.seo-head', compact('seo'))->render();
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;/script&gt;', $html);
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match);
        $schema = json_decode($match[1], true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame($seo['title'], $schema['@graph'][1]['name']);
        $this->assertSame($name, $schema['@graph'][2]['itemListElement'][2]['name']);
        $this->assertSame(1, substr_count($html, 'rel="canonical"'));
        $updated = app(PageSeo::class)->withDescription($seo, 'Цель: найти предмет.');
        $this->assertSame('Цель: найти предмет.', $updated['description']);
        $this->assertSame($updated['description'], $updated['schema']['@graph'][1]['description']);
    }

    public function test_cached_catalog_reloads_after_content_changes_even_with_the_same_size(): void
    {
        $directory = sys_get_temp_dir().'/wiki-seo-test-'.bin2hex(random_bytes(8));
        mkdir($directory.'/data', 0700, true);
        $original = public_path(); $file = $directory.'/data/page-routes.json';
        try {
            $this->app->usePublicPath($directory);
            file_put_contents($file, '{"quests":{"test":{"name":"Первый","slug":"first"}}}');
            $seo = app(PageSeo::class);
            $this->assertStringContainsString('Первый', $seo->metadata('quest', ['quest'=>'test'])['title']);
            $snapshot = $seo->catalogSnapshot();
            file_put_contents($file, '{"quests":{"test":{"name":"Второй","slug":"other"}}}');
            $this->assertStringContainsString('Второй', $seo->metadata('quest', ['quest'=>'test'])['title']);
            $this->assertStringEndsWith('/quests/other', $seo->metadata('quest', ['quest'=>'test'])['canonical']);
            $this->assertStringContainsString('Первый', $snapshot->metadata('quest', ['quest'=>'test'])['title']);
            $this->assertStringEndsWith('/quests/first', $snapshot->metadata('quest', ['quest'=>'test'])['canonical']);
            $this->assertStringContainsString('Второй', $snapshot->catalogSnapshot()->metadata('quest', ['quest'=>'test'])['title']);
        } finally {
            $this->app->usePublicPath($original);
            unlink($file); rmdir($directory.'/data'); rmdir($directory);
        }
    }
}
