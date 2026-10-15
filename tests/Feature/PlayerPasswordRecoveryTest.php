<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Mail\ResetPlayerPassword;
use App\Services\{PlayerEmails, PlayerPasswordRecovery};
use EftWiki\Identity\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail};
use Tests\TestCase;

/** Восстанавливает прежнего владельца прогресса, не раскрывая наличие адреса. */
final class PlayerPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();$this->encryptCookies=false;$this->withCredentials();
        config(['player-auth.email.mailer'=>'array']);Mail::fake();
    }
    private function guest(): array
    {
        $this->defaultCookies=[];$r=$this->getJson('/api/session')->assertOk();
        return [$r->headers->getCookies()[0]->getValue(),$r->json('csrf'),$r->json('profile.id')];
    }
    private function owner(string $name='old_recovery_player',bool $confirmed=true): array
    {
        $guest=$this->guest();$r=$this->withCookie('eft_session',$guest[0])->postJson('/api/auth/register',
            ['username'=>$name,'password'=>'old password 12345'],['Origin'=>'https://wiki.test','X-CSRF-Token'=>$guest[1]])->assertOk();
        $who=[$r->headers->getCookies()[0]->getValue(),$r->json('csrf'),$r->json('profile.id')];
        if($confirmed)DB::table('player_emails')->insert(['user_id'=>$who[2],'email'=>'old@example.test','verified_at'=>time()]);
        return $who;
    }
    private function recoveryPost(array $who,string $route,array $data)
    {return $this->withCookie('eft_session',$who[0])->postJson('/api/auth/password/'.$route,$data,['Origin'=>'https://wiki.test','X-CSRF-Token'=>$who[1]]);}
    private function requestCode(array $guest,string $email='old@example.test'): array
    {
        $r=$this->recoveryPost($guest,'request',['email'=>$email])->assertOk();$code='';
        Mail::assertSent(ResetPlayerPassword::class,function($mail)use(&$code){$code=$mail->code;return true;});
        $this->assertMatchesRegularExpression('/^[0-9]{8}$/D',$code);$this->assertStringNotContainsString($code,$r->getContent());
        return ['challenge_id'=>$r->json('challengeId'),'code'=>$code,'password'=>'new password 67890','password_confirmation'=>'new password 67890'];
    }
    public function test_reset_preserves_everything_except_password_and_revokes_old_sessions(): void
    {
        $owner=$this->owner();$guest=$this->guest();
        DB::table('player_profiles')->insert(['user_id'=>$owner[2],'display_name'=>'Старый игрок','provider_name'=>'Имя Google','updated_at'=>time()]);
        foreach(['kord-atlas-quests-pve-v1','kord-hideout-pve','kord-achievements-pve','kord-atlas-quests-regular-v1'] as $key){
            DB::table('user_state')->insert(['user_id'=>$owner[2],'state_key'=>$key,'value'=>'{"oldProgress":true}','version'=>7,'updated_at'=>time()]);
        }
        DB::table('player_identities')->insert(['provider'=>'discord','subject'=>'old-discord','user_id'=>$owner[2],'display_name'=>'Старый Discord','created_at'=>time()]);
        $before=(array)DB::table('users')->where('id',$owner[2])->first();
        $saved=[];foreach(['user_state','player_profiles','player_identities','player_emails'] as $table){$saved[$table]=DB::table($table)->where('user_id',$owner[2])->get()->toJson();}
        $code=$this->requestCode($guest,' Old@Example.Test ');
        $pending=DB::table('player_password_resets')->first();
        $this->assertSame(1800,(int)$pending->expires_at-(int)$pending->sent_at);
        $this->assertNotSame($code['code'],$pending->code_hash);$this->assertSame(64,strlen($pending->code_hash));
        DB::table('player_oauth_states')->insert(['token_hash'=>str_repeat('f',64),'session_hash'=>hash('sha256',$owner[0]),'provider'=>'google','link_user_id'=>$owner[2],'expires_at'=>time()+600]);
        $this->recoveryPost($guest,'reset',$code)->assertOk()->assertJsonPath('username','old_recovery_player');
        $after=(array)DB::table('users')->where('id',$owner[2])->first();
        $this->assertTrue(password_verify($code['password'],$after['password_hash']));
        unset($before['password_hash'],$after['password_hash']);$this->assertSame($before,$after);
        foreach($saved as $table=>$rows){$this->assertSame($rows,DB::table($table)->where('user_id',$owner[2])->get()->toJson());}
        $this->assertSame(0,DB::table('player_password_resets')->count());$this->assertSame(0,DB::table('player_oauth_states')->count());
        $this->withCookie('eft_session',$owner[0])->getJson('/api/state')->assertUnauthorized();
        $this->recoveryPost($guest,'reset',$code)->assertUnprocessable();
        $this->withCookie('eft_session',$guest[0])->postJson('/api/auth/login',['username'=>'old_recovery_player','password'=>'old password 12345'],
            ['Origin'=>'https://wiki.test','X-CSRF-Token'=>$guest[1]])->assertUnauthorized();
        $login=$this->withCookie('eft_session',$guest[0])->postJson('/api/auth/login',['username'=>'old_recovery_player','password'=>$code['password']],
            ['Origin'=>'https://wiki.test','X-CSRF-Token'=>$guest[1]])->assertOk()->assertJsonPath('profile.id',$owner[2]);
        $this->withCookie('eft_session',$login->headers->getCookies()[0]->getValue())->getJson('/api/state')->assertJsonPath('state.kord-hideout-pve.version',7);
    }
    public function test_unknown_unconfirmed_and_verified_provider_only_addresses_get_the_same_response(): void
    {
        $owner=$this->owner(confirmed:false);$guest=$this->guest();
        DB::table('player_identities')->insert(['provider'=>'google','subject'=>'provider','user_id'=>$owner[2],'display_name'=>'Player','created_at'=>time(),
            'verified_email'=>'provider@example.test','email_auto_link'=>true]);
        foreach(['missing@example.test','unconfirmed@example.test','provider@example.test'] as $email){
            $r=$this->recoveryPost($guest,'request',['email'=>$email])->assertOk();
            $this->assertSame(['challengeId','expiresAt','resendAt','message'],array_keys($r->json()));
            $this->assertStringStartsWith('Если эта почта подтверждена',$r->json('message'));
            $this->assertTrue($r->headers->hasCacheControlDirective('no-store'));$this->assertTrue($r->headers->hasCacheControlDirective('private'));
        }
        Mail::assertNothingSent();$this->assertSame(0,DB::table('player_password_resets')->count());
    }
    public function test_wrong_session_wrong_codes_and_exhausted_attempts_cannot_reset_password(): void
    {
        $owner=$this->owner();$guest=$this->guest();$other=$this->guest();$code=$this->requestCode($guest);
        $this->recoveryPost($other,'reset',$code)->assertUnprocessable()->assertJsonPath('message',PlayerPasswordRecovery::INVALID_CODE);
        $this->assertSame(0,(int)DB::table('player_password_resets')->value('attempts'));
        $wrong=$code;$wrong['code']=$code['code']==='00000000'?'11111111':'00000000';
        for($i=0;$i<5;$i++){$this->recoveryPost($guest,'reset',$wrong)->assertUnprocessable()->assertJsonPath('message',PlayerPasswordRecovery::INVALID_CODE);}
        $this->recoveryPost($guest,'reset',$code)->assertUnprocessable()->assertJsonPath('message',PlayerPasswordRecovery::INVALID_CODE);
        $this->assertTrue(password_verify('old password 12345',DB::table('users')->where('id',$owner[2])->value('password_hash')));
    }
    public function test_expired_replaced_and_contact_or_password_changed_codes_are_rejected(): void
    {
        $owner=$this->owner();$guest=$this->guest();$first=$this->requestCode($guest);
        DB::table('rate_limits')->where('bucket','like','password-reset:cooldown:%')->update(['expires_at'=>time()-1]);
        Mail::fake();$second=$this->requestCode($guest);
        $this->recoveryPost($guest,'reset',$first)->assertUnprocessable();
        DB::table('player_password_resets')->update(['expires_at'=>time()-1]);$this->recoveryPost($guest,'reset',$second)->assertUnprocessable();
        DB::table('player_password_resets')->update(['expires_at'=>time()+600]);
        DB::table('player_emails')->update(['email'=>'changed@example.test']);$this->recoveryPost($guest,'reset',$second)->assertUnprocessable();
        DB::table('player_emails')->update(['email'=>'old@example.test']);
        DB::table('users')->where('id',$owner[2])->update(['password_hash'=>password_hash('another password 123',PASSWORD_DEFAULT)]);
        $this->recoveryPost($guest,'reset',$second)->assertUnprocessable();
    }
    public function test_csrf_origin_password_confirmation_and_password_bytes_are_validated(): void
    {
        $this->postJson('/api/auth/password/request',['email'=>'old@example.test'])->assertUnauthorized();
        $this->owner();$guest=$this->guest();$code=$this->requestCode($guest);
        $this->withCookie('eft_session',$guest[0])->postJson('/api/auth/password/reset',$code,['Origin'=>'https://evil.test','X-CSRF-Token'=>$guest[1]])->assertForbidden();
        $this->withCookie('eft_session',$guest[0])->postJson('/api/auth/password/request',['email'=>'old@example.test'],['Origin'=>'https://wiki.test','X-CSRF-Token'=>'wrong'])->assertForbidden();
        $this->recoveryPost($guest,'reset',[...$code,'password_confirmation'=>'different'])->assertUnprocessable();
        foreach(['short',str_repeat('я',37),"nul\0password123456"] as $password){$this->recoveryPost($guest,'reset',[...$code,'password'=>$password,'password_confirmation'=>$password])->assertUnprocessable();}
        $this->assertSame(0,(int)DB::table('player_password_resets')->value('attempts'));
        $this->recoveryPost($guest,'reset',$code)->assertOk();
    }
    public function test_request_cooldown_and_quotas_do_not_reveal_account_existence(): void
    {
        $this->owner();$guest=$this->guest();
        $this->recoveryPost($guest,'request',['email'=>'old@example.test'])->assertOk();
        $this->recoveryPost($guest,'request',['email'=>'old@example.test'])->assertStatus(429);
        $this->recoveryPost($guest,'request',['email'=>'missing@example.test'])->assertOk();
        $this->recoveryPost($guest,'request',['email'=>'missing@example.test'])->assertStatus(429);
        foreach(['old@example.test','missing@example.test'] as $email){
            DB::table('rate_limits')->where('bucket','like','password-reset:cooldown:%')->update(['expires_at'=>time()-1]);
            $this->recoveryPost($guest,'request',['email'=>$email])->assertOk();
            DB::table('rate_limits')->where('bucket','like','password-reset:cooldown:%')->update(['expires_at'=>time()-1]);
            $this->recoveryPost($guest,'request',['email'=>$email])->assertStatus(429);
        }
    }
    public function test_mail_failure_is_generic_and_does_not_leave_a_valid_code(): void
    {
        $this->owner();$guest=$this->guest();Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('SMTP secret details'));
        $this->recoveryPost($guest,'request',['email'=>'old@example.test'])->assertOk()->assertDontSee('SMTP secret');
        $this->assertSame(0,DB::table('player_password_resets')->count());
        config(['player-auth.email.mailer'=>'log']);$this->recoveryPost($guest,'request',['email'=>'other@example.test'])->assertStatus(503);
    }
    public function test_ip_limit_counts_unknown_addresses_and_bad_addresses_never_send_mail(): void
    {
        $guest=$this->guest();
        $this->recoveryPost($guest,'request',['email'=>"bad@example.test\r\nBcc:other@example.test"])->assertUnprocessable();
        for($i=0;$i<10;$i++){$this->recoveryPost($guest,'request',['email'=>'unknown'.$i.'@example.test'])->assertOk();}
        $this->recoveryPost($guest,'request',['email'=>'extra@example.test'])->assertStatus(429);
        Mail::assertNothingSent();
    }
    public function test_recovery_mail_uses_english_subject_and_russian_body(): void
    {
        $mail=new ResetPlayerPassword('01234567');
        $this->assertSame('Tarkov Compass - Password reset code',$mail->envelope()->subject);
        $body=$mail->render();$this->assertStringContainsString('Код: 01234567',$body);$this->assertStringContainsString('30 минут',$body);
    }
    public function test_revoked_session_cannot_finish_oauth_that_started_before_reset(): void
    {
        $owner=$this->owner();$guest=$this->guest();$reset=$this->requestCode($guest);
        config(['player-auth.google'=>['client_id'=>'id','client_secret'=>'secret']]);
        $begin=$this->withCookie('eft_session',$owner[0])->postJson('/api/auth/oauth/google/start',[],['Origin'=>'https://wiki.test','X-CSRF-Token'=>$owner[1]])->assertOk();
        parse_str(parse_url($begin->json('url'),PHP_URL_QUERY),$query);
        Http::fake(['oauth2.googleapis.com/token'=>Http::response(['access_token'=>'token']),
            'openidconnect.googleapis.com/v1/userinfo'=>function()use($guest,$reset){
                $session=app(AccountService::class)->session($guest[0]);app(PlayerPasswordRecovery::class)->reset($session,$reset['challenge_id'],$reset['code'],$reset['password']);
                return Http::response(['sub'=>'attacker-link','name'=>'Other']);
            }]);
        $this->withCookie('eft_session',$owner[0])->postJson('/api/auth/oauth/google/finish',['state'=>$query['state'],'code'=>'code'],
            ['Origin'=>'https://wiki.test','X-CSRF-Token'=>$owner[1]])->assertUnauthorized();
        $this->assertSame(0,DB::table('player_identities')->count());
        $stale=new \EftWiki\Identity\Session($owner[0],$owner[2],'old_recovery_player',time()+1000);
        try{app(PlayerEmails::class)->send($stale,'different@example.test','127.0.0.1');$this->fail('Отозванная сессия не должна менять почту');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(401,$e->getStatusCode());}
    }
}
