<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\{PageCatalog, PageViews};
use Illuminate\Support\Facades\{Cache, View};
use Tests\TestCase;

/** Проверяет Laravel-страницы, общий кэш и инвалидацию исходников. */
final class SiteTest extends TestCase
{
    public function test_laravel_resolves_public_routes_without_exported_route_files(): void
    {
        $this->assertStringStartsWith('13.', $this->app->version());
        foreach (['/', '/account', '/documents', '/raid/interchange', '/quests/the-ticket', '/quests/trader/prapor',
                  '/quests/story', '/reference/items/5447a9cd4bdc2dbd208b4567', '/boss/reshala', '/quest-map/customs', '/quest-map/guide-20261016-groundzero-terragroup'] as $url) {
            $response = $this->get($url);
            $response->assertOk()->assertSee('name="wiki-runtime" content="php"', false);
            $this->assertFalse($response->headers->has('Set-Cookie'));
        }
        foreach (['/quests/unknown', '/raid/unknown', '/reference/items/invalid', '/%2e%2e/bootstrap'] as $url) {
            $this->get($url)->assertNotFound()->assertSee('name="wiki-runtime" content="php"', false);
        }
        $this->assertCount(7058, $this->app->make(PageCatalog::class)->routes());
    }

    public function test_preloads_match_selected_module_graph_without_other_page_entrypoints(): void
    {
        $urls=app(\App\Services\PageModules::class)->preloads('reference');
        $this->assertContains(config('pages.reference.module'),$urls);
        $this->assertCount(count(array_unique($urls)),$urls);
        foreach(['public-content-cache.mjs','profile-store.mjs','reference-loader.mjs'] as $module){
            $this->assertCount(1,array_filter($urls,fn($url)=>strtok($url,'?')===$module));
        }
        foreach(['raid.mjs','app.mjs','quests.mjs','account.mjs'] as $module){
            $this->assertCount(0,array_filter($urls,fn($url)=>strtok($url,'?')===$module));
        }
        $html=$this->get('/reference/items/5447a9cd4bdc2dbd208b4567')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.config('pages.reference.module').'"  data-page-module',$html);
        $views=app(PageViews::class);$before=$views->fingerprint();$file=public_path('reference.mjs');$source=file_get_contents($file);
        try{file_put_contents($file,$source."\n// Проверка зависимости preload\n");$this->assertNotSame($before,$views->fingerprint());}
        finally{file_put_contents($file,$source);}
    }

    public function test_head_and_etag_revalidation_do_not_return_page_body(): void
    {
        $page = $this->get('/raid/shoreline?mode=pve');
        $page->assertOk();
        $this->get('/raid/shoreline?mode=regular', ['If-None-Match' => $page->headers->get('ETag')])->assertStatus(304)->assertContent('');
        $this->head('/quests/story')->assertOk()->assertContent('');
    }

    public function test_gallery_css_version_does_not_require_css_in_api_package(): void
    {
        $file = public_path('guide.css');
        $temporary = $file.'.gallery-test';
        $version = substr(hash('sha256', str_replace("\r\n", "\n", file_get_contents($file))), 0, 16);
        $this->assertFileDoesNotExist($temporary);
        rename($file, $temporary);
        try {
            Cache::flush();
            $this->get('/quests/wiring-the-vessel')->assertOk()->assertSee('guide.css?v='.$version, false);
        } finally {
            rename($temporary, $file);
        }
    }

    public function test_initial_header_does_not_guess_guest_status_before_session_check(): void
    {
        foreach(['/', '/account', '/documents', '/raid/lighthouse', '/quests', '/reference'] as $url){
            $html=$this->get($url)->assertOk()->getContent();$dom=new \DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xpath=new \DOMXPath($dom);
            $this->assertSame(2,$xpath->query('//*[@data-player-guest and @hidden]')->length,$url);
            $this->assertSame(0,$xpath->query('//*[@data-player-guest and not(@hidden)]')->length,$url);
            $this->assertSame('Личный кабинет',$xpath->query('//*[@id="profile-button" and @aria-busy="true"]')->item(0)?->textContent,$url);
        }
    }

    public function test_public_view_cache_hits_and_separates_php_from_static_runtime(): void
    {
        Cache::flush(); $calls = 0;
        View::composer('pages.raid', function () use (&$calls): void { $calls++; });
        $views = $this->app->make(PageViews::class);
        $first = $views->render('raid');
        $this->assertSame($first, $views->render('raid'));
        $this->assertSame(1, $calls);
        $this->assertStringContainsString('content="static"', $views->render('raid', 'static'));
        $this->assertSame(2, $calls);
        $this->assertStringNotContainsString('eft_session=', $first);
    }

    public function test_route_cache_invalidates_when_json_content_changes(): void
    {
        $file = public_path('data/page-routes.json'); $original = file_get_contents($file);
        $catalog = $this->app->make(PageCatalog::class);
        $this->assertNull($catalog->resolve('/quests/cache-fixture'));
        try {
            $data = json_decode($original, true, 64, JSON_THROW_ON_ERROR);
            $data['quests']['cache-fixture'] = ['slug' => 'cache-fixture', 'name' => 'Кэш <безопасность>'];
            file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->get('/quests/cache-fixture')->assertOk()->assertSee('Кэш &lt;безопасность&gt;', false);
        } finally { file_put_contents($file, $original); Cache::flush(); }
        $this->get('/quests/cache-fixture')->assertNotFound();
    }

    public function test_view_fingerprint_invalidates_when_blade_changes(): void
    {
        $views = $this->app->make(PageViews::class); $before = $views->fingerprint();
        $file = resource_path('views/layouts/site.blade.php'); $original = file_get_contents($file);
        try {
            file_put_contents($file, $original."\n{{-- Проверка инвалидации --}}\n");
            $this->assertNotSame($before, $views->fingerprint());
        } finally { file_put_contents($file, $original); }
        $this->assertSame($before, $views->fingerprint());
    }

    public function test_artisan_export_matches_blade_sources(): void
    {
        $this->artisan('site:export')->assertSuccessful();
        $views = $this->app->make(PageViews::class);
        foreach (array_keys(config('pages')) as $page) {
            $this->assertSame($views->render($page, 'static'), file_get_contents(public_path(($page === 'not-found' ? '404' : $page).'.html')));
        }
        $manifest = json_decode(file_get_contents(public_path('data/view-export.json')), true);
        $this->assertSame($views->fingerprint(), $manifest['source_hash']);
    }
}
