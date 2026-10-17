<?php
declare(strict_types=1);
namespace Tests\Feature;

use Tests\TestCase;

/** Часы получают серверное время без сессий и общей персонализации. */
final class GameTimeTest extends TestCase
{
    public function test_public_clock_has_server_time_and_never_creates_a_cookie(): void
    {
        $before = time() * 1000;
        $response = $this->getJson('/api/content/time')->assertOk()->assertJsonPath('scale', 7)->assertJsonPath('offsetMilliseconds', 10800000);
        $this->assertGreaterThanOrEqual($before, $response->json('unixMilliseconds'));
        $this->assertLessThanOrEqual(time() * 1000, $response->json('unixMilliseconds'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        config(['api.proxy_required' => true, 'api.proxy_token' => str_repeat('secret-', 10)]);
        $this->getJson('/api/content/time')->assertForbidden();
    }
}
