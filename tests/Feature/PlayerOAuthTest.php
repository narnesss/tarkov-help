<?php
declare(strict_types=1);
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

/** Проверяет смену сессии, одноразовый state и сохранение владельца прогресса. */
final class PlayerOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();$this->encryptCookies=false;$this->withCredentials();
        config(['player-auth.google'=>['client_id'=>'google-test','client_secret'=>'private-google-secret'],
            'player-auth.discord'=>['client_id'=>'discord-test','client_secret'=>'private-discord-secret']]);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token'=>fn($request)=>$request['code']==='invalid'?Http::response(['error'=>'invalid_grant'],400):Http::response(['access_token'=>'google-access']),
            'openidconnect.googleapis.com/v1/userinfo'=>Http::response(['sub'=>'google-user-1','name'=>'Игрок Google','email'=>'same@example.test']),
            'discord.com/api/oauth2/token'=>Http::response(['access_token'=>'discord-access']),
            'discord.com/api/users/@me'=>Http::response(['id'=>'123456789','global_name'=>'Игрок Discord','email'=>'same@example.test']),
        ]);
    }

    private function guest(): array
    {
        $this->defaultCookies=[];$response=$this->getJson('/api/session')->assertOk();
        return [$response->headers->getCookies()[0]->getValue(),$response->json('csrf'),$response->json('profile.id')];
    }

    private function authPost(array $who,string $route,array $data=[])
    {
        return $this->withCookie('eft_session',$who[0])->postJson($route,$data,['Origin'=>'https://wiki.test','X-CSRF-Token'=>$who[1]]);
    }

    private function start(array $who,string $provider='google'): string
    {
        $r=$this->authPost($who,'/api/auth/oauth/'.$provider.'/start')->assertOk();parse_str(parse_url($r->json('url'),PHP_URL_QUERY),$query);
        $this->assertSame('https://wiki.test/account?oauth='.$provider,$query['redirect_uri']);
        $this->assertStringNotContainsString('secret',$r->getContent());
        return $query['state'];
    }

    public function test_options_fail_closed_and_do_not_disclose_secrets(): void
    {
        $who=$this->guest();config(['player-auth.google.client_secret'=>'']);
        $r=$this->withCookie('eft_session',$who[0])->getJson('/api/auth/options')->assertOk()
            ->assertJsonPath('providers.0.enabled',false)->assertJsonPath('providers.1.enabled',true);
        $this->assertStringNotContainsString('private-discord-secret',$r->getContent());
        $this->assertTrue($r->headers->hasCacheControlDirective('private'));$this->assertTrue($r->headers->hasCacheControlDirective('no-store'));
        $this->authPost($who,'/api/auth/oauth/google/start')->assertStatus(503);
        $this->assertSame(0,DB::table('player_oauth_states')->count());
    }

    public function test_start_requires_proxy_session_origin_and_hmac(): void
    {
        $this->postJson('/api/auth/oauth/google/start')->assertUnauthorized();$who=$this->guest();
        $this->withCookie('eft_session',$who[0])->postJson('/api/auth/oauth/google/start',[],['Origin'=>'https://evil.test','X-CSRF-Token'=>$who[1]])->assertForbidden();
        $this->withCookie('eft_session',$who[0])->postJson('/api/auth/oauth/google/start',[],['Origin'=>'https://wiki.test','X-CSRF-Token'=>'fake'])->assertForbidden();
        config(['api.proxy_required'=>true,'api.proxy_token'=>str_repeat('t',64)]);
        $this->authPost($who,'/api/auth/oauth/google/start')->assertForbidden();
        $this->assertSame(0,DB::table('player_oauth_states')->count());Http::assertNothingSent();
    }

    public function test_google_login_preserves_account_and_progress_on_next_login(): void
    {
        $guest=$this->guest();$state=$this->start($guest);
        $registered=$this->authPost($guest,'/api/auth/oauth/google/finish',['state'=>$state,'code'=>'first-code'])->assertOk()->assertJsonPath('profile.authenticated',true);
        $id=$registered->json('profile.id');$cookie=$registered->headers->getCookies()[0];
        $this->assertTrue($cookie->isSecure());$this->assertTrue($cookie->isHttpOnly());
        $who=[$cookie->getValue(),$registered->json('csrf'),$id];
        $this->withCookie('eft_session',$guest[0])->getJson('/api/state')->assertUnauthorized();
        $this->withCookie('eft_session',$who[0])->putJson('/api/state/kord-achievements-pve',['value'=>['award-1'],'version'=>0],['Origin'=>'https://wiki.test','X-CSRF-Token'=>$who[1]])->assertOk();
        $this->authPost($who,'/api/auth/logout')->assertOk();
        $next=$this->guest();$state=$this->start($next);
        $login=$this->authPost($next,'/api/auth/oauth/google/finish',['state'=>$state,'code'=>'second-code'])->assertOk()->assertJsonPath('profile.id',$id);
        $this->withCookie('eft_session',$login->headers->getCookies()[0]->getValue())->getJson('/api/state')->assertJsonPath('state.kord-achievements-pve.value.0','award-1');
        $this->assertSame(1,DB::table('player_identities')->count());
        $this->assertNull(DB::table('users')->where('id',$id)->value('password_hash'));
        $this->assertSame(0,DB::table('player_oauth_states')->count());
    }

    public function test_state_is_bound_to_session_provider_and_can_only_be_used_once(): void
    {
        $a=$this->guest();$b=$this->guest();$state=$this->start($a);
        $this->authPost($b,'/api/auth/oauth/google/finish',['state'=>$state,'code'=>'stolen'])->assertForbidden();
        $this->authPost($a,'/api/auth/oauth/discord/finish',['state'=>$state,'code'=>'wrong-provider'])->assertForbidden();
        Http::assertNothingSent();
        $this->authPost($a,'/api/auth/oauth/google/finish',['state'=>$state,'code'=>'correct'])->assertOk();
        $this->authPost($b,'/api/auth/oauth/google/finish',['state'=>$state,'code'=>'replay'])->assertForbidden();
        Http::assertSentCount(2);
    }

    public function test_expired_or_failed_exchange_cannot_create_an_account(): void
    {
        $who=$this->guest();$state=$this->start($who);
        DB::table('player_oauth_states')->update(['expires_at'=>time()-1]);
        $this->authPost($who,'/api/auth/oauth/google/finish',['state'=>$state,'code'=>'expired'])->assertForbidden();Http::assertNothingSent();
        $state=$this->start($who);
        $this->authPost($who,'/api/auth/oauth/google/finish',['state'=>$state,'code'=>'invalid'])->assertStatus(502);
        $this->assertSame(0,DB::table('player_identities')->count());$this->assertSame(1,DB::table('users')->count());
    }

    public function test_discord_can_be_linked_without_changing_existing_id_or_progress(): void
    {
        $guest=$this->guest();$r=$this->authPost($guest,'/api/auth/register',['username'=>'старый_игрок','password'=>'correct horse battery'])->assertOk();
        $who=[$r->headers->getCookies()[0]->getValue(),$r->json('csrf'),$r->json('profile.id')];
        $state=$this->start($who,'discord');$r=$this->authPost($who,'/api/auth/oauth/discord/finish',['state'=>$state,'code'=>'discord-code'])->assertOk()->assertJsonPath('profile.id',$who[2]);
        $this->assertSame($who[2],DB::table('player_identities')->value('user_id'));
        $this->assertNotNull(DB::table('users')->where('id',$who[2])->value('password_hash'));
        $linked=$this->withCookie('eft_session',$r->headers->getCookies()[0]->getValue())->getJson('/api/auth/options')->assertOk()->assertJsonPath('providers.1.linked',true);
        $other=$this->guest();$r=$this->authPost($other,'/api/auth/register',['username'=>'другой_игрок','password'=>'correct horse battery'])->assertOk();
        $other=[$r->headers->getCookies()[0]->getValue(),$r->json('csrf'),$r->json('profile.id')];$state=$this->start($other,'discord');
        $this->authPost($other,'/api/auth/oauth/discord/finish',['state'=>$state,'code'=>'taken'])->assertConflict();
        $this->assertSame($who[2],DB::table('player_identities')->value('user_id'));
    }

    public function test_matching_emails_never_merge_google_and_discord_accounts(): void
    {
        $a=$this->guest();$state=$this->start($a);$first=$this->authPost($a,'/api/auth/oauth/google/finish',['state'=>$state,'code'=>'google'])->assertOk();
        $b=$this->guest();$state=$this->start($b,'discord');$second=$this->authPost($b,'/api/auth/oauth/discord/finish',['state'=>$state,'code'=>'discord'])->assertOk();
        $this->assertNotSame($first->json('profile.id'),$second->json('profile.id'));
        $this->assertSame(2,DB::table('player_identities')->count());
    }
}
