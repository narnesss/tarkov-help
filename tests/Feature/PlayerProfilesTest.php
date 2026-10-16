<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\PlayerProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Проверяет права изменения имени и неизменность ID, пароля и прогресса. */
final class PlayerProfilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_can_be_changed_without_changing_session_login_or_progress(): void
    {
        $this->encryptCookies=false; $this->withCredentials();
        $guest=$this->getJson('/api/session')->assertOk();
        $r=$this->withCookie('eft_session',$guest->headers->getCookies()[0]->getValue())->postJson('/api/auth/register',
            ['username'=>'старый_логин','password'=>'Cedar!Kite9_Moon'],['Origin'=>'https://wiki.test','X-CSRF-Token'=>$guest->json('csrf')])->assertOk();
        $id=$r->json('profile.id');$cookie=$r->headers->getCookies()[0]->getValue();
        $headers=['Origin'=>'https://wiki.test','X-CSRF-Token'=>$r->json('csrf')];
        $hash=DB::table('users')->where('id',$id)->value('password_hash');
        DB::table('user_state')->insert(['user_id'=>$id,'state_key'=>'kord-achievements-pve','value'=>'["earned"]','version'=>3,'updated_at'=>time()]);
        $this->withCookie('eft_session',$cookie)->postJson('/api/auth/profile',['display_name'=>'  Позывной  '],$headers)->assertOk()
            ->assertJsonPath('profile.id',$id)->assertJsonPath('profile.name','Позывной')->assertJsonPath('profile.customName',true);
        $this->withCookie('eft_session',$cookie)->getJson('/api/session')->assertOk()->assertJsonPath('profile.name','Позывной');
        $this->assertSame('старый_логин',DB::table('users')->where('id',$id)->value('username'));
        $this->assertSame($hash,DB::table('users')->where('id',$id)->value('password_hash'));
        $this->assertSame(3,(int)DB::table('user_state')->where('user_id',$id)->value('version'));
        app(PlayerProfiles::class)->initializeOAuth($id,'Имя провайдера',null);
        $this->withCookie('eft_session',$cookie)->postJson('/api/auth/profile',['display_name'=>null],$headers)->assertUnprocessable();
        $this->withCookie('eft_session',$cookie)->getJson('/api/session')->assertOk()->assertJsonPath('profile.name','Позывной')->assertJsonPath('profile.authenticated',true);
    }

    public function test_guests_csrf_and_invalid_names_are_rejected(): void
    {
        $this->encryptCookies=false; $this->withCredentials();
        $this->postJson('/api/auth/profile',['display_name'=>'Test'])->assertUnauthorized();
        $guest=$this->getJson('/api/session')->assertOk();$cookie=$guest->headers->getCookies()[0]->getValue();
        $headers=['Origin'=>'https://wiki.test','X-CSRF-Token'=>$guest->json('csrf')];
        $this->withCookie('eft_session',$cookie)->postJson('/api/auth/profile',['display_name'=>'Test'],$headers)->assertForbidden();
        $r=$this->withCookie('eft_session',$cookie)->postJson('/api/auth/register',['username'=>'normal','password'=>'Cedar!Kite9_Moon'],$headers)->assertOk();
        $cookie=$r->headers->getCookies()[0]->getValue();$headers['X-CSRF-Token']=$r->json('csrf');
        $this->withCookie('eft_session',$cookie)->postJson('/api/auth/profile',['display_name'=>'New'],['Origin'=>'https://evil.test','X-CSRF-Token'=>$headers['X-CSRF-Token']])->assertForbidden();
        $this->withCookie('eft_session',$cookie)->postJson('/api/auth/profile',['display_name'=>'New'],['Origin'=>'https://wiki.test','X-CSRF-Token'=>'invalid'])->assertForbidden();
        foreach(['',' ',str_repeat('я',65),'<script>',"Имя\u{202E}скрыто"] as $name){
            $this->withCookie('eft_session',$cookie)->postJson('/api/auth/profile',['display_name'=>$name],$headers)->assertUnprocessable();
        }
        $this->assertSame(0,DB::table('player_profiles')->count());
    }

    public function test_avatar_only_allows_expected_provider_hosts(): void
    {
        foreach(['javascript:alert(1)','http://lh3.googleusercontent.com/a','https://lh3.googleusercontent.com.evil.test/a',
            'https://name@lh3.googleusercontent.com/a','https://lh3.googleusercontent.com:443/a'] as $url){
            $this->assertNull(PlayerProfiles::avatar('google',['picture'=>$url]));
        }
        $this->assertNull(PlayerProfiles::avatar('discord',['id'=>'123','avatar'=>'../invalid']));
        $this->assertSame('https://lh3.googleusercontent.com/a',PlayerProfiles::avatar('google',['picture'=>'https://lh3.googleusercontent.com/a']));
    }
}
