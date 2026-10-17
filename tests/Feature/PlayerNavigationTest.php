<?php
declare(strict_types=1);
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Проверяет HTML до выполнения JavaScript и отсутствие записи в аккаунты/прогресс. */
final class PlayerNavigationTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    { parent::setUp();$this->encryptCookies=false;$this->withCredentials(); }

    private function owner(?string $name='navigation_test',int $lifetime=60): string
    {
        $id=str_repeat('a',32);$token=str_repeat('b',64);
        DB::table('users')->insert(['id'=>$id,'username'=>$name,'password_hash'=>null,'created_at'=>time()]);
        DB::table('sessions')->insert(['token_hash'=>hash('sha256',$token),'user_id'=>$id,'expires_at'=>time()+$lifetime]);
        DB::table('user_state')->insert(['user_id'=>$id,'state_key'=>'progress','value'=>'{"complete":["quest-stable"]}','version'=>4,'updated_at'=>time()]);
        return $token;
    }
    private function snapshot(): string
    { return serialize([DB::table('users')->get()->all(),DB::table('sessions')->get()->all(),DB::table('user_state')->get()->all()]); }

    public function test_authenticated_html_contains_no_login_or_registration_nodes(): void
    {
        $token=$this->owner();$before=$this->snapshot();
        $response=$this->withCookie('eft_session',$token)->getJson('/api/player-navigation')->assertOk()
            ->assertHeader('Cache-Control','no-store, private')->assertHeader('Vary','Cookie');
        $html=$response->json('html');$this->assertStringContainsString('Личный кабинет',$html);
        foreach(['data-player-guest','player-login','player-register','view=login','view=register'] as $value){$this->assertStringNotContainsString($value,$html);}
        $this->assertSame($before,$this->snapshot());
    }
    public function test_anonymous_html_keeps_both_links_without_creating_a_session(): void
    {
        $before=$this->snapshot();$html=$this->getJson('/api/player-navigation')->assertOk()->json('html');
        $this->assertStringContainsString('view=login',$html);$this->assertStringContainsString('view=register',$html);
        $this->assertSame($before,$this->snapshot());
    }
    public function test_guest_expired_and_forged_cookies_do_not_authenticate_or_change_data(): void
    {
        $token=$this->owner(null);$before=$this->snapshot();
        foreach([$token,str_repeat('c',64),"' OR 1=1 --"] as $cookie){
            $this->assertStringContainsString('view=login',$this->withCookie('eft_session',$cookie)->getJson('/api/player-navigation')->assertOk()->json('html'));
        }
        $this->assertSame($before,$this->snapshot());
        DB::table('users')->update(['username'=>'registered']);DB::table('sessions')->update(['expires_at'=>time()-1]);$before=$this->snapshot();
        $this->assertStringContainsString('view=login',$this->withCookie('eft_session',$token)->getJson('/api/player-navigation')->assertOk()->json('html'));
        $this->assertSame($before,$this->snapshot());
    }
    public function test_proxy_boundary_and_page_validation_are_preserved(): void
    {
        config(['api.proxy_required'=>true,'api.proxy_token'=>str_repeat('t',64)]);
        $this->getJson('/api/player-navigation')->assertForbidden();
        $headers=['X-Wiki-Proxy-Token'=>str_repeat('t',64),'X-Wiki-Client-IP'=>'198.51.100.1'];
        $this->getJson('/api/player-navigation?page[]=account',$headers)->assertUnprocessable();
        $this->getJson('/api/player-navigation',$headers)->assertOk();
        $this->assertSame(0,DB::table('users')->count());
    }
}
