<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Mail\VerifyPlayerEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Tests\TestCase;

/** Подтверждение принадлежит аккаунту, не меняет прогресс и не раскрывает одноразовые коды. */
final class PlayerEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();$this->encryptCookies=false;$this->withCredentials();
        config(['player-auth.email.mailer'=>'array']);Mail::fake();
    }
    private function account(string $name='old_player'): array
    {
        $this->defaultCookies=[];$guest=$this->getJson('/api/session')->assertOk();
        $r=$this->withCookie('eft_session',$guest->headers->getCookies()[0]->getValue())->postJson('/api/auth/register',
            ['username'=>$name,'password'=>'correct horse battery'],['Origin'=>'https://wiki.test','X-CSRF-Token'=>$guest->json('csrf')])->assertOk();
        return [$r->headers->getCookies()[0]->getValue(),$r->json('csrf'),$r->json('profile.id')];
    }
    private function emailPost(array $who,string $route,array $data)
    {return $this->withCookie('eft_session',$who[0])->postJson('/api/auth/email/'.$route,$data,['Origin'=>'https://wiki.test','X-CSRF-Token'=>$who[1]]);}
    private function send(array $who,string $email='player@gmail.com'): array
    {
        $r=$this->emailPost($who,'send',['email'=>$email])->assertOk();$code='';
        Mail::assertSent(VerifyPlayerEmail::class,function($mail)use(&$code,$email){if($mail->hasTo(strtolower(trim($email)))){$code=$mail->code;return true;}return false;});
        $this->assertMatchesRegularExpression('/^[0-9]{8}$/D',$code);
        $this->assertStringNotContainsString($code,$r->getContent());
        $this->assertNotSame($code,DB::table('player_email_challenges')->where('user_id',$who[2])->value('code_hash'));
        return ['challenge_id'=>$r->json('pending.challengeId'),'code'=>$code];
    }
    public function test_confirming_email_preserves_id_password_and_progress(): void
    {
        $who=$this->account();$before=(array)DB::table('users')->where('id',$who[2])->first();
        DB::table('user_state')->insert(['user_id'=>$who[2],'state_key'=>'kord-achievements-pve','value'=>'["earned"]','version'=>6,'updated_at'=>time()]);
        $code=$this->send($who,' Player@Gmail.com ');
        $this->assertSame(0,DB::table('player_emails')->count());
        $r=$this->emailPost($who,'verify',$code)->assertOk()->assertJsonPath('email','player@gmail.com')->assertJsonPath('pending',null);
        $this->assertTrue($r->headers->hasCacheControlDirective('no-store'));$this->assertTrue($r->headers->hasCacheControlDirective('private'));
        $this->assertSame($before,(array)DB::table('users')->where('id',$who[2])->first());
        $this->assertSame(6,(int)DB::table('user_state')->where('user_id',$who[2])->value('version'));
        $this->emailPost($who,'verify',$code)->assertGone();
        $this->emailPost($who,'send',['email'=>'player@gmail.com'])->assertOk()->assertJsonPath('sent',false);
        Mail::assertSent(VerifyPlayerEmail::class,1);
    }
    public function test_code_is_bound_to_user_and_wrong_attempts_are_limited(): void
    {
        $a=$this->account('player_a');$b=$this->account('player_b');$code=$this->send($a);
        $this->emailPost($b,'verify',$code)->assertGone();
        $wrong=$code;$wrong['code']=$code['code']==='00000000'?'11111111':'00000000';
        for($i=0;$i<5;$i++){
            $this->emailPost($a,'verify',$wrong)->assertUnprocessable()->assertJsonPath('message','Неверный код подтверждения.');
            $this->withCookie('eft_session',$a[0])->getJson('/api/auth/email')->assertOk()->assertJsonPath('pending.attemptsLeft',4-$i);
        }
        $this->emailPost($a,'verify',$code)->assertStatus(429);
        $this->assertSame(5,(int)DB::table('player_email_challenges')->value('attempts'));
        $this->assertSame(0,DB::table('player_emails')->count());
    }
    public function test_code_lasts_thirty_minutes_and_mail_has_english_subject_and_russian_body(): void
    {
        $who=$this->account();$code=$this->send($who);
        $pending=DB::table('player_email_challenges')->first();
        $this->assertSame(1800,(int)$pending->expires_at-(int)$pending->sent_at);
        DB::table('player_email_challenges')->update(['sent_at'=>time()-1200,'expires_at'=>time()+600]);
        $this->emailPost($who,'verify',$code)->assertOk();
        $mail=(new VerifyPlayerEmail('12345678'))->from('narnes@alwaysdata.net')->to('player@example.test');
        $this->assertStringContainsString('Код действует 30 минут.',$mail->render());
        $manager=new \Illuminate\Mail\MailManager($this->app);
        $message=$manager->mailer('array')->send($mail)->getSymfonySentMessage()->getOriginalMessage();
        $subject=$message->getHeaders()->get('Subject')->toString();
        $this->assertSame('Subject: Tarkov Compass - Email verification code',$subject);
        $this->assertStringContainsString('Подтверждение e-mail в Tarkov Compass',$message->getTextBody());
        $this->assertStringContainsString('Код: 12345678',$message->getTextBody());
        foreach(explode("\r\n",$subject) as $line){$this->assertLessThanOrEqual(78,strlen($line));}
    }
    public function test_expired_and_replaced_codes_do_not_verify_and_old_contact_remains(): void
    {
        $who=$this->account();$first=$this->send($who);$this->emailPost($who,'verify',$first)->assertOk();
        $second=$this->send($who,'new@gmail.com');
        $this->assertSame('player@gmail.com',DB::table('player_emails')->value('email'));
        $this->emailPost($who,'send',['email'=>'third@gmail.com'])->assertStatus(429);
        DB::table('player_email_challenges')->update(['sent_at'=>time()-61]);
        $third=$this->send($who,'third@gmail.com');
        $this->emailPost($who,'verify',$second)->assertGone();
        DB::table('player_email_challenges')->update(['expires_at'=>time()-1]);
        $this->emailPost($who,'verify',$third)->assertGone();
        $this->assertSame('player@gmail.com',DB::table('player_emails')->value('email'));
    }
    public function test_email_claim_conflicts_are_checked_before_send_and_again_before_confirm(): void
    {
        $a=$this->account('player_a');$b=$this->account('player_b');$code=$this->send($a);
        DB::table('player_emails')->insert(['user_id'=>$b[2],'email'=>'player@gmail.com','verified_at'=>time()]);
        $this->emailPost($a,'verify',$code)->assertConflict();
        $this->emailPost($a,'send',['email'=>'player@gmail.com'])->assertConflict();
        $this->assertSame($b[2],DB::table('player_emails')->value('user_id'));
        DB::table('player_identities')->insert(['provider'=>'google','subject'=>'other-google','user_id'=>$b[2],'display_name'=>'Other','created_at'=>time(),'verified_email'=>'taken@gmail.com','email_auto_link'=>true]);
        $this->emailPost($a,'send',['email'=>'taken@gmail.com'])->assertConflict();
    }
    public function test_guest_origin_csrf_and_log_mailer_cannot_send_verification_codes(): void
    {
        $this->postJson('/api/auth/email/send',['email'=>'player@gmail.com'])->assertUnauthorized();
        $guest=$this->getJson('/api/session')->assertOk();
        $this->withCookie('eft_session',$guest->headers->getCookies()[0]->getValue())->getJson('/api/auth/email')->assertForbidden();
        $who=$this->account();
        $this->withCookie('eft_session',$who[0])->postJson('/api/auth/email/send',['email'=>'player@gmail.com'],['Origin'=>'https://evil.test','X-CSRF-Token'=>$who[1]])->assertForbidden();
        $this->withCookie('eft_session',$who[0])->postJson('/api/auth/email/send',['email'=>'player@gmail.com'],['Origin'=>'https://wiki.test','X-CSRF-Token'=>'invalid'])->assertForbidden();
        $this->emailPost($who,'send',['email'=>"x@gmail.com\r\nBcc:evil@test.test"])->assertUnprocessable();
        config(['player-auth.email.mailer'=>'log']);$this->emailPost($who,'send',['email'=>'player@gmail.com'])->assertStatus(503);
        Mail::assertNothingSent();$this->assertSame(0,DB::table('player_email_challenges')->count());
    }
    public function test_mail_failure_does_not_leave_a_usable_challenge(): void
    {
        $who=$this->account();Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('Не выводить SMTP details'));
        $this->emailPost($who,'send',['email'=>'player@gmail.com'])->assertStatus(503)->assertDontSee('SMTP details');
        $this->assertSame(0,DB::table('player_email_challenges')->count());
    }
    public function test_hourly_quotas_cannot_be_bypassed_by_changing_address_or_account(): void
    {
        $who=$this->account();
        for($i=0;$i<5;$i++){
            $this->emailPost($who,'send',['email'=>'target'.$i.'@gmail.com'])->assertOk();
            DB::table('player_email_challenges')->where('user_id',$who[2])->update(['sent_at'=>time()-61]);
        }
        $this->emailPost($who,'send',['email'=>'sixth@gmail.com'])->assertStatus(429);
        foreach(['other_a','other_b','other_c'] as $name){$this->emailPost($this->account($name),'send',['email'=>'shared@gmail.com'])->assertOk();}
        $this->emailPost($this->account('other_d'),'send',['email'=>'shared@gmail.com'])->assertStatus(429);
        Mail::assertSent(VerifyPlayerEmail::class,8);
    }
}
