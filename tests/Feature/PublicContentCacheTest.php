<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\{PublicContentCache, PublicMedia};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Проверяет политику публичных ответов отдельно от запросов и файлов предметного домена. */
final class PublicContentCacheTest extends TestCase
{
    public function test_revision_matches_previous_contract_and_both_checks_skip_cache_and_builder(): void
    {
        config(['public-media.aliases' => []]);
        $expected = hash('sha256', "page-content-counts-20261017-v1\0key\0source\0".PublicMedia::revision());
        Cache::shouldReceive('get')->never();Cache::shouldReceive('put')->never();
        $build = function (): never { $this->fail('Проверка версии не должна строить контент'); };
        $service = new PublicContentCache;
        $old = $service->respond(Request::create('/?revision=1'), 'key', 'source', $build);
        $this->assertSame(['revision' => $expected], json_decode($old->getContent(), true));
        $this->assertFalse($old->headers->has('X-Wiki-Content-Unchanged'));
        $request = Request::create('/');$request->headers->set('X-Wiki-Content-Revision', $expected);
        $new = $service->respond($request, 'key', 'source', $build);
        $this->assertSame($old->getContent(), $new->getContent());
        $this->assertSame('VALIDATED', $new->headers->get('X-Wiki-Content-Cache'));
        $this->assertSame('1', $new->headers->get('X-Wiki-Content-Unchanged'));
        $this->assertSame('no-store, private', $new->headers->get('Cache-Control'));
        $this->assertFalse($new->headers->has('Set-Cookie'));
    }

    public function test_corrupt_cache_is_rebuilt_once_and_new_revision_replaces_the_same_slot(): void
    {
        Cache::flush();$service = new PublicContentCache;$request = Request::create('/');$calls = 0;
        $build = function () use (&$calls): string { $calls++;return '{"name":"Публичное имя"}'; };
        $first = $service->respond($request, 'public-test', 'v1', $build);
        $this->assertSame('MISS', $first->headers->get('X-Wiki-Content-Cache'));
        $revision = $first->headers->get('X-Wiki-Content-Revision');
        Cache::put('public-test', ['revision' => $revision, 'gzip' => 'broken'], 86400);
        $fixed = $service->respond($request, 'public-test', 'v1', $build);
        $this->assertSame('MISS', $fixed->headers->get('X-Wiki-Content-Cache'));
        $warm = $service->respond($request, 'public-test', 'v1', $build);
        $this->assertSame('HIT', $warm->headers->get('X-Wiki-Content-Cache'));
        $this->assertSame($first->getContent(), $warm->getContent());$this->assertSame(2, $calls);
        $service->respond($request, 'public-test', 'v2', $build);
        $this->assertSame(3, $calls);$this->assertCount(1, Cache::store()->getStore()->all());
        $this->assertNotSame($revision, Cache::get('public-test')['revision']);
    }

    public function test_failed_build_is_not_cached_and_can_be_retried(): void
    {
        Cache::flush();$service = new PublicContentCache;$request = Request::create('/');
        try {
            $service->respond($request, 'missing', 'v1', static function (): never { abort(404); });
            $this->fail('Ошибка сборки должна сохранять HTTP-статус');
        } catch (HttpException $error) { $this->assertSame(404, $error->getStatusCode()); }
        $this->assertNull(Cache::get('missing'));
        $response = $service->respond($request, 'missing', 'v1', static fn () => '{"ready":true}');
        $this->assertSame('MISS', $response->headers->get('X-Wiki-Content-Cache'));
        $this->assertSame('{"ready":true}', $response->getContent());
    }
}
