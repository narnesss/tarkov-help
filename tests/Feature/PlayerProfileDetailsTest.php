<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Mail\ResetPlayerPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Tests\TestCase;

/** Проверяет приватность анкеты и сохранность аккаунта при её изменении. */
final class PlayerProfileDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $this->encryptCookies=false;$this->withCredentials();
        $guest=$this->getJson('/api/session')->assertOk();
        $response=$this->withCookie('eft_session',$guest->headers->getCookies()[0]->getValue())
            ->postJson('/api/auth/register',['username'=>'profile_details','password'=>'Cedar!Kite9_Moon'],
                ['Origin'=>'https://wiki.test','X-CSRF-Token'=>$guest->json('csrf')])->assertOk();
        $this->withCookie('eft_session',$response->headers->getCookies()[0]->getValue());
        return [$response->json('profile.id'),['Origin'=>'https://wiki.test','X-CSRF-Token'=>$response->json('csrf')]];
    }

    public function test_details_preserve_contacts_identity_password_and_progress(): void
    {
        [$id,$headers]=$this->owner();
        DB::table('player_emails')->insert(['user_id'=>$id,'email'=>'owner@example.test','verified_at'=>time()]);
        DB::table('user_state')->insert(['user_id'=>$id,'state_key'=>'kord-achievements-pve','value'=>'["earned"]','version'=>4,'updated_at'=>time()]);
        $before=(array)DB::table('users')->where('id',$id)->first();
        $version=$this->getJson('/api/auth/profile')->assertOk()->json('version');
        $values=['profile_version'=>$version,'display_name'=>'  Игрок  ','phone'=>'+7 (999) 123-45-67','country'=>' Россия ','city'=>' Москва ','gender'=>'male','age'=>30];
        $this->postJson('/api/auth/profile',$values,$headers)->assertOk()->assertJsonPath('profile.id',$id)->assertJsonPath('profile.name','Игрок')
            ->assertJsonMissingPath('profile.phone')->assertJsonMissingPath('profile.email');
        $this->getJson('/api/auth/profile')->assertOk()->assertHeader('Cache-Control','no-store, private')
            ->assertJsonPath('phone','+79991234567')->assertJsonPath('email','owner@example.test')->assertJsonPath('country','Россия')
            ->assertJsonPath('city','Москва')->assertJsonPath('age',30)->assertJsonPath('registeredAt',(int)$before['created_at']);
        $this->assertSame($before,(array)DB::table('users')->where('id',$id)->first());
        $this->assertSame(4,(int)DB::table('user_state')->where('user_id',$id)->value('version'));
        $this->postJson('/api/auth/profile',['display_name'=>'Другое имя'],$headers)->assertOk();
        $this->assertSame('Москва',DB::table('player_profiles')->where('user_id',$id)->value('city'));
        $this->postJson('/api/auth/profile',$values,$headers)->assertConflict();
        $version=$this->getJson('/api/auth/profile')->assertOk()->json('version');
        $this->postJson('/api/auth/profile',['profile_version'=>$version,'display_name'=>'Игрок','phone'=>null,'country'=>null,'city'=>null,'gender'=>null,'age'=>null],$headers)->assertOk();
        $this->getJson('/api/auth/profile')->assertOk()->assertJsonPath('phone',null)->assertJsonPath('city',null)->assertJsonPath('email','owner@example.test');
    }

    public function test_backend_rejects_bad_values_and_readonly_contact_without_partial_save(): void
    {
        [$id,$headers]=$this->owner();
        $version=$this->getJson('/api/auth/profile')->assertOk()->json('version');
        foreach([['phone'=>['bad']],['country'=>['bad']],['city'=>'<img src=x onerror=alert(1)>'],['city'=>"City\u{202E}"],
            ['gender'=>['male']],['gender'=>'unknown'],['age'=>121],['age'=>1.5],['phone'=>'+0123456789'],['email'=>'evil@example.test'],
            ['email'=>null],['registeredAt'=>1],['lastActiveAt'=>time()]] as $bad){
            $this->postJson('/api/auth/profile',['profile_version'=>$version,'display_name'=>'Не сохранять',...$bad],$headers)->assertUnprocessable();
            $this->assertDatabaseMissing('player_profiles',['user_id'=>$id]);
        }
        $this->post('/api/auth/profile',['display_name'=>"\xFF"],[...$headers,'Accept'=>'application/json'])->assertUnprocessable();
        $this->postJson('/api/auth/profile',['display_name'=>'Игрок'],['Origin'=>'https://evil.test','X-CSRF-Token'=>$headers['X-CSRF-Token']])->assertForbidden();
    }

    public function test_details_are_owner_only_and_activity_tracks_personal_requests(): void
    {
        $this->getJson('/api/auth/profile')->assertUnauthorized();
        [$id]=$this->owner();
        DB::table('player_activity')->where('user_id',$id)->update(['last_active_at'=>time()-120]);
        $this->getJson('/api/state')->assertOk();
        $this->assertGreaterThanOrEqual(time()-2,(int)DB::table('player_activity')->where('user_id',$id)->value('last_active_at'));
        $this->getJson('/api/session')->assertOk()->assertJsonMissingPath('profile.phone')->assertJsonMissingPath('profile.city');
        DB::table('sessions')->where('user_id',$id)->delete();
        DB::table('users')->where('id',$id)->delete();
        $this->assertDatabaseMissing('player_activity',['user_id'=>$id]);
    }

    public function test_signed_in_reset_uses_own_verified_email_and_never_replaces_password_when_requesting(): void
    {
        [$id,$headers]=$this->owner();
        config(['player-auth.email.mailer'=>'array']);Mail::fake();
        $hash=DB::table('users')->where('id',$id)->value('password_hash');
        $this->postJson('/api/auth/password/request',['email'=>'other@example.test'],$headers)->assertUnprocessable();
        DB::table('player_emails')->insert(['user_id'=>$id,'email'=>'owner@example.test','verified_at'=>time()]);
        $this->postJson('/api/auth/password/request',['email'=>'other@example.test'],$headers)->assertOk();
        Mail::assertSent(ResetPlayerPassword::class,fn ($mail) => $mail->hasTo('owner@example.test'));
        $this->assertSame('owner@example.test',DB::table('player_password_resets')->where('user_id',$id)->value('email'));
        $this->assertSame($hash,DB::table('users')->where('id',$id)->value('password_hash'));
    }

    public function test_provider_email_is_readonly_and_sql_like_text_remains_plain_text(): void
    {
        [$id,$headers]=$this->owner();
        DB::table('player_identities')->insert(['user_id'=>$id,'provider'=>'google','subject'=>'test-google',
            'display_name'=>'Имя Google','verified_email'=>'google@example.test','email_auto_link'=>false,'created_at'=>time()]);
        $details=$this->getJson('/api/auth/profile?user_id=another')->assertOk()
            ->assertJsonPath('email','google@example.test')->assertJsonPath('passwordResetAvailable',false);
        $text="City'); DROP TABLE users; --";
        $this->postJson('/api/auth/profile',['display_name'=>'Игрок','city'=>$text,'profile_version'=>$details->json('version')],$headers)->assertOk();
        $this->assertDatabaseHas('users',['id'=>$id]);
        $this->assertSame($text,DB::table('player_profiles')->where('user_id',$id)->value('city'));
        $this->getJson('/api/auth/profile')->assertOk()->assertJsonPath('city',$text);
    }
}
