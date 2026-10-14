<?php
declare(strict_types=1);
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB};
use Tests\TestCase;

/** Реальные HTTP-проверки совместимости API, владельца, CSRF и CAS через Laravel. */
final class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    { parent::setUp(); $this->encryptCookies = false; $this->withCredentials(); }

    private function identity(): array
    {
        $this->defaultCookies = [];
        $response = $this->getJson('/api/session')->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('private')); $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $cookie = $response->headers->getCookies()[0];
        $this->assertTrue($cookie->isHttpOnly()); $this->assertTrue($cookie->isSecure());
        return [$cookie->getValue(), $response->json('csrf'), $response->json('profile.id')];
    }

    private function write(array $identity, array $data, ?string $csrf = null, string $origin = 'https://wiki.test')
    {
        return $this->withCookie('eft_session', $identity[0])->putJson('/api/state/wiki-ui-v1', $data,
            ['X-CSRF-Token' => $csrf ?? $identity[1], 'Origin' => $origin]);
    }

    public function test_api_keeps_owners_isolated_and_rejects_stale_versions(): void
    {
        $a = $this->identity(); $b = $this->identity();
        $this->assertNotSame($a[2], $b[2]);
        $this->write($a, ['value' => ['query' => 'private'], 'version' => 0, 'userId' => $b[2]])->assertOk()->assertJsonPath('version', 1);
        $this->withCookie('eft_session', $b[0])->getJson('/api/state')->assertOk()->assertExactJson(['state' => []]);
        $this->withCookie('eft_session', $a[0])->getJson('/api/state')->assertJsonPath('state.wiki-ui-v1.value.query', 'private');
        $this->write($a, ['value' => [], 'version' => 0])->assertStatus(409);
        $this->write($a, ['value' => ['query' => 'updated'], 'version' => 1])->assertOk()->assertJsonPath('version', 2);
    }

    public function test_csrf_and_origin_checks_run_in_the_laravel_http_pipeline(): void
    {
        $this->putJson('/api/state/wiki-ui-v1', ['value' => [], 'version' => 0])->assertUnauthorized();
        $identity = $this->identity();
        $this->write($identity, ['value' => [], 'version' => 0], 'forged')->assertForbidden();
        $this->write($identity, ['value' => [], 'version' => 0], null, 'https://evil.test')->assertForbidden();
        $this->write($identity, ['value' => [], 'version' => 0])->assertOk();
        $this->write($identity, ['value' => 'invalid', 'version' => 1])->assertStatus(422);
        $this->write($identity, ['value' => ['large' => str_repeat('x', 600000)], 'version' => 1])->assertStatus(413);
    }

    public function test_register_logout_login_preserves_identity_and_rotates_sessions(): void
    {
        $guest = $this->identity();
        $registered = $this->withCookie('eft_session', $guest[0])->postJson('/api/auth/register',
            ['username' => 'Игрок_01', 'password' => 'correct horse battery'], ['X-CSRF-Token' => $guest[1], 'Origin' => 'https://wiki.test'])->assertOk();
        $registered->assertJsonPath('profile.authenticated', true);
        $account = $registered->json('profile.id');
        $this->withCookie('eft_session', $guest[0])->getJson('/api/state')->assertUnauthorized();
        $cookie = $registered->headers->getCookies()[0]->getValue();
        $this->withCookie('eft_session', $cookie)->postJson('/api/auth/logout', [],
            ['X-CSRF-Token' => $registered->json('csrf'), 'Origin' => 'https://wiki.test'])->assertOk()->assertJsonPath('profile.authenticated', false);
        $this->withCookie('eft_session', $cookie)->getJson('/api/state')->assertUnauthorized();
        $next = $this->identity();
        $this->withCookie('eft_session', $next[0])->postJson('/api/auth/login',
            ['username' => 'ИГРОК_01', 'password' => 'correct horse battery'], ['X-CSRF-Token' => $next[1], 'Origin' => 'https://wiki.test'])->assertOk()->assertJsonPath('profile.id', $account);
    }

    public function test_private_api_does_not_populate_public_cache_or_personalize_pages(): void
    {
        Cache::flush(); $before = $this->get('/raid')->getContent();
        $identity = $this->identity();
        $this->write($identity, ['value' => ['query' => 'only-my-private-value'], 'version' => 0])->assertOk();
        $api = $this->withCookie('eft_session', $identity[0])->getJson('/api/state')->assertOk();
        $this->assertTrue($api->headers->hasCacheControlDirective('private')); $this->assertTrue($api->headers->hasCacheControlDirective('no-store'));
        $page = $this->get('/raid')->assertOk();
        $this->assertSame($before, $page->getContent());
        $this->assertStringNotContainsString('only-my-private-value', $page->getContent());
        $this->assertFalse($page->headers->has('Set-Cookie'));
        $this->assertSame(1, DB::table('user_state')->count());
        foreach (['/api/prices', '/api/prices/daily'] as $url) {
            $this->getJson($url)->assertStatus(410)->assertJsonPath('code', 'prices_disabled');
        }
    }

    public function test_migrations_preserve_existing_profile_rows_on_repeat(): void
    {
        $identity = $this->identity();
        $this->write($identity, ['value' => ['keep' => true], 'version' => 0])->assertOk();
        $migration = require database_path('migrations/2026_10_15_000001_preserve_wiki_storage.php');
        $migration->up();
        $this->withCookie('eft_session', $identity[0])->getJson('/api/state')->assertJsonPath('state.wiki-ui-v1.value.keep', true);
    }
}
