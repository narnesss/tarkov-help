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
    private array $googleUser=['sub'=>'google-user-1','name'=>'Игрок Google','email'=>'same@example.test','picture'=>'https://lh3.googleusercontent.com/avatar'];
    private array $discordUser=['id'=>'123456789','global_name'=>'Игрок Discord','email'=>'same@example.test'];

    protected function setUp(): void
    {
        parent::setUp();$this->encryptCookies=false;$this->withCredentials();
        config(['player-auth.google'=>['client_id'=>'google-test','client_secret'=>'private-google-secret'],
            'player-auth.discord'=>['client_id'=>'discord-test','client_secret'=>'private-discord-secret']]);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token'=>fn($request)=>$request['code']==='invalid'?Http::response(['error'=>'invalid_grant'],400):Http::response(['access_token'=>'google-access']),
            'openidconnect.googleapis.com/v1/userinfo'=>fn()=>Http::response($this->googleUser),
            'discord.com/api/oauth2/token'=>Http::response(['access_token'=>'discord-access']),
            'discord.com/api/users/@me'=>fn()=>Http::response($this->discordUser),
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
        $registered->assertJsonPath('profile.name','Игрок Google')->assertJsonPath('profile.avatar','https://lh3.googleusercontent.com/avatar');
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

    public function test_unverified_matching_emails_never_merge_google_and_discord_accounts(): void
    {
        $a=$this->guest();$state=$this->start($a);$first=$this->authPost($a,'/api/auth/oauth/google/finish',['state'=>$state,'code'=>'google'])->assertOk();
        $b=$this->guest();$state=$this->start($b,'discord');$second=$this->authPost($b,'/api/auth/oauth/discord/finish',['state'=>$state,'code'=>'discord'])->assertOk();
        $this->assertNotSame($first->json('profile.id'),$second->json('profile.id'));
        $this->assertSame(2,DB::table('player_identities')->count());
    }

    private function finishGoogle(array $who,array $user)
    {
        $this->googleUser=$user;
        return $this->authPost($who,'/api/auth/oauth/google/finish',['state'=>$this->start($who),'code'=>'google-code']);
    }

    private function finishDiscord(array $who,array $user)
    {
        $this->discordUser=$user;
        return $this->authPost($who,'/api/auth/oauth/discord/finish',['state'=>$this->start($who,'discord'),'code'=>'discord-code']);
    }

    public function test_verified_email_automatically_links_both_providers_to_existing_progress(): void
    {
        $first=$this->finishGoogle($this->guest(),['sub'=>'first-google','name'=>'Имя Google','email'=>' Player@Gmail.com ','email_verified'=>true])->assertOk();
        $id=$first->json('profile.id');
        DB::table('user_state')->insert(['user_id'=>$id,'state_key'=>'kord-achievements-pve','value'=>'["award-1"]','version'=>7,'updated_at'=>time()]);
        $second=$this->finishDiscord($this->guest(),['id'=>'987654321','username'=>'discord-user','global_name'=>'Имя Discord','email'=>'player@gmail.com','verified'=>true,'avatar'=>str_repeat('a',32)])
            ->assertOk()->assertJsonPath('profile.id',$id)->assertJsonPath('profile.name','Имя Discord')
            ->assertJsonPath('profile.avatar','https://cdn.discordapp.com/avatars/987654321/'.str_repeat('a',32).'.png?size=128');
        $this->assertSame(2,DB::table('player_identities')->where('user_id',$id)->count());
        $this->withCookie('eft_session',$second->headers->getCookies()[0]->getValue())->getJson('/api/state')->assertJsonPath('state.kord-achievements-pve.version',7);
        // Email остаётся приватным атрибутом сервера и не попадает в session response.
        $this->assertStringNotContainsString('player@gmail.com',$second->getContent());
    }

    public function test_verified_email_links_google_to_discord_and_workspace_is_supported(): void
    {
        $first=$this->finishDiscord($this->guest(),['id'=>'777','username'=>'existing','email'=>'player@company.test','verified'=>true])->assertOk();
        $this->finishGoogle($this->guest(),['sub'=>'workspace','name'=>'Сотрудник','email'=>'player@company.test','email_verified'=>true,'hd'=>'company.test'])
            ->assertOk()->assertJsonPath('profile.id',$first->json('profile.id'));
    }

    public function test_google_external_mail_and_false_verified_flags_require_existing_login(): void
    {
        $first=$this->finishDiscord($this->guest(),['id'=>'777','username'=>'existing','email'=>'player@mail.ru','verified'=>true])->assertOk();
        $who=$this->guest();
        $this->finishGoogle($who,['sub'=>'external','email'=>'player@mail.ru','email_verified'=>true])->assertConflict();
        $this->assertSame(1,DB::table('player_identities')->count());
        $this->finishGoogle($who,['sub'=>'unverified','email'=>'player@mail.ru','email_verified'=>'true'])->assertOk()->assertJsonMissing(['id'=>$first->json('profile.id')]);
        $this->assertNull(DB::table('player_identities')->where('subject','unverified')->value('verified_email'));
    }

    public function test_a_provider_already_connected_elsewhere_cannot_be_reassigned_by_email(): void
    {
        $a=$this->finishGoogle($this->guest(),['sub'=>'google-a','email'=>'player@gmail.com','email_verified'=>true])->assertOk();
        $b=$this->finishDiscord($this->guest(),['id'=>'777','username'=>'second','email'=>'other@gmail.com','verified'=>true])->assertOk();
        // Уже связанный subject сохраняет владельца даже после изменения почты.
        $this->finishDiscord($this->guest(),['id'=>'777','username'=>'second','email'=>'player@gmail.com','verified'=>true])->assertOk()->assertJsonPath('profile.id',$b->json('profile.id'));
        $this->finishGoogle($this->guest(),['sub'=>'google-c','email'=>'player@gmail.com','email_verified'=>true])->assertConflict();
        $this->assertSame(2,DB::table('player_identities')->count());
        $this->assertNotSame($a->json('profile.id'),$b->json('profile.id'));
    }

    public function test_second_subject_of_same_provider_cannot_replace_existing_connection(): void
    {
        $a=$this->finishGoogle($this->guest(),['sub'=>'google-a','email'=>'player@gmail.com','email_verified'=>true])->assertOk();
        $this->finishGoogle($this->guest(),['sub'=>'google-b','email'=>'player@gmail.com','email_verified'=>true])->assertConflict();
        $this->assertSame(1,DB::table('player_identities')->count());
        $this->assertSame($a->json('profile.id'),DB::table('player_identities')->value('user_id'));
    }

    public function test_custom_name_survives_provider_refresh_and_unsafe_avatar_is_not_used(): void
    {
        $first=$this->finishGoogle($this->guest(),['sub'=>'google-a','name'=>'Имя Google','picture'=>'javascript:alert(1)'])->assertOk()->assertJsonPath('profile.avatar',null);
        $who=[$first->headers->getCookies()[0]->getValue(),$first->json('csrf'),$first->json('profile.id')];
        $this->authPost($who,'/api/auth/profile',['display_name'=>'Мой позывной'])->assertOk()->assertJsonPath('profile.name','Мой позывной');
        $this->finishGoogle($this->guest(),['sub'=>'google-a','name'=>'Новое имя','picture'=>'https://evil.test/avatar'])->assertOk()
            ->assertJsonPath('profile.name','Мой позывной')->assertJsonPath('profile.customName',true)->assertJsonPath('profile.avatar',null);
        $this->assertSame('Новое имя',DB::table('player_profiles')->where('user_id',$who[2])->value('provider_name'));
    }
}
