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
                  '/quests/story', '/reference/items/5447a9cd4bdc2dbd208b4567', '/boss/reshala', '/quest-map/customs'] as $url) {
            $response = $this->get($url);
            $response->assertOk()->assertSee('name="wiki-runtime" content="php"', false);
            $this->assertFalse($response->headers->has('Set-Cookie'));
        }
        foreach (['/quests/unknown', '/raid/unknown', '/reference/items/invalid', '/%2e%2e/bootstrap'] as $url) {
            $this->get($url)->assertNotFound()->assertSee('name="wiki-runtime" content="php"', false);
        }
        $this->assertCount(7006, $this->app->make(PageCatalog::class)->routes());
    }

    public function test_head_and_etag_revalidation_do_not_return_page_body(): void
    {
        $page = $this->get('/raid/shoreline?mode=pve');
        $page->assertOk();
        $this->get('/raid/shoreline?mode=regular', ['If-None-Match' => $page->headers->get('ETag')])->assertStatus(304)->assertContent('');
        $this->head('/quests/story')->assertOk()->assertContent('');
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
