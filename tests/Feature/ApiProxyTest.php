<?php
declare(strict_types=1);
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Проверяет границу alwaysdata/Worker и изоляцию rate-limit по настоящему IP. */
final class ApiProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->encryptCookies = false; $this->withCredentials();
        config(['api.proxy_required' => true, 'api.proxy_token' => str_repeat('t', 64)]);
    }

    private function proxy(string $ip = '198.51.100.1'): array
    { return ['X-Wiki-Proxy-Token' => str_repeat('t', 64), 'X-Wiki-Client-IP' => $ip]; }

    public function test_direct_requests_cannot_issue_sessions_and_missing_secret_fails_closed(): void
    {
        $response = $this->getJson('/api/session')->assertForbidden();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame(0, DB::table('users')->count());
        $this->getJson('/api/session', ['X-Wiki-Proxy-Token' => 'forged'])->assertForbidden();
        config(['api.proxy_token' => '']);
        $this->getJson('/api/session', $this->proxy())->assertStatus(503);
    }

    public function test_proxy_ip_is_validated_and_csrf_origin_is_still_checked(): void
    {
        $this->getJson('/api/session', $this->proxy('forged'))->assertStatus(400);
        $response = $this->getJson('/api/session', $this->proxy())->assertOk();
        $this->withCookie('eft_session', $response->headers->getCookies()[0]->getValue())
            ->putJson('/api/state/wiki-ui-v1', ['value' => [], 'version' => 0],
                [...$this->proxy(), 'Origin' => 'https://evil.test', 'X-CSRF-Token' => $response->json('csrf')])->assertForbidden();
        $this->assertSame(0, DB::table('user_state')->count());
    }

    public function test_login_limits_use_verified_client_ip_instead_of_shared_proxy_address(): void
    {
        $response = $this->getJson('/api/session', $this->proxy())->assertOk();
        $this->withCookie('eft_session', $response->headers->getCookies()[0]->getValue());
        foreach (['198.51.100.1', '198.51.100.2'] as $ip) {
            $this->postJson('/api/auth/login', ['username' => 'missing', 'password' => 'wrong'],
                [...$this->proxy($ip), 'Origin' => 'https://wiki.test', 'X-CSRF-Token' => $response->json('csrf')])->assertUnauthorized();
        }
        $this->assertSame(2, DB::table('rate_limits')->count());
        $this->assertSame(1, DB::table('rate_limits')->max('count'));
    }

    public function test_public_health_does_not_require_proxy_secret_or_create_a_session(): void
    {
        $this->get('/up')->assertOk();
        $this->assertSame(0, DB::table('users')->count());
    }
}
