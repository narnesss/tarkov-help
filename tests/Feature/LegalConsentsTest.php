<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\LegalConsents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;

/** Проверяет самостоятельные согласия и сохранность прежнего аккаунта на временной БД. */
final class LegalConsentsTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    { parent::setUp();$this->encryptCookies=false;$this->withCredentials();config(['legal.required'=>true]); }
    private function guest(): array
    { $this->defaultCookies=[];$r=$this->getJson('/api/session')->assertOk();return [$r->headers->getCookies()[0]->getValue(),$r->json('csrf'),$r->json('profile.id')]; }
    private function accepted(): array
    { return ['version'=>app(LegalConsents::class)->version(),'terms'=>true,'privacy'=>true,'personal_data'=>true]; }
    private function postAs(array $who,string $path,array $data)
    { return $this->withCookie('eft_session',$who[0])->postJson($path,$data,['Origin'=>'https://wiki.test','X-CSRF-Token'=>$who[1]]); }
    public function test_registration_requires_three_independent_confirmations_and_current_version(): void
    {
        $who=$this->guest();$data=['username'=>'legal_user','password'=>'Cedar!Kite9_Moon'];$accepted=$this->accepted();
        foreach([[],['legal'=>'bad'],['legal'=>[...$accepted,'terms'=>false]],['legal'=>[...$accepted,'privacy'=>false]],
            ['legal'=>[...$accepted,'personal_data'=>false]],['legal'=>[...$accepted,'personal_data'=>'true']],['legal'=>[...$accepted,'version'=>str_repeat('0',64)]]] as $input){
            $this->postAs($who,'/api/auth/register',[...$data,...$input])->assertUnprocessable();
            $this->assertDatabaseCount('users',1);$this->assertDatabaseCount('player_legal_consents',0);
        }
        $r=$this->postAs($who,'/api/auth/register',[...$data,'legal'=>$accepted])->assertOk()->assertJsonPath('profile.authenticated',true);
        $owner=$r->json('profile.id');$this->assertNotSame($who[2],$owner);$this->assertDatabaseCount('player_legal_consents',3);
        foreach(LegalConsents::DOCUMENTS as $document){$this->assertDatabaseHas('player_legal_consents',['user_id'=>$owner,'document'=>$document,'version'=>$accepted['version'],'method'=>'password']);}
        $this->assertDatabaseCount('user_state',0);
    }
    public function test_csrf_is_checked_before_consent_and_failed_password_does_not_create_receipt(): void
    {
        $who=$this->guest();$data=['username'=>'legal_user','password'=>'weak','legal'=>$this->accepted()];
        $this->withCookie('eft_session',$who[0])->postJson('/api/auth/register',$data,['Origin'=>'https://wiki.test','X-CSRF-Token'=>'forged'])->assertForbidden();
        $this->postAs($who,'/api/auth/register',$data)->assertUnprocessable();$this->assertDatabaseCount('users',1);$this->assertDatabaseCount('player_legal_consents',0);
    }
    public function test_oauth_requires_consent_before_contacting_provider_and_preserves_it_through_state(): void
    {
        config(['player-auth.google'=>['client_id'=>'test-client','client_secret'=>'private-test']]);
        Http::preventStrayRequests();Http::fake(['oauth2.googleapis.com/token'=>Http::response(['access_token'=>'test']),
            'openidconnect.googleapis.com/v1/userinfo'=>Http::response(['sub'=>'legal-google-user','name'=>'Игрок','email'=>'legal@example.test','email_verified'=>true])]);
        $who=$this->guest();$this->postAs($who,'/api/auth/oauth/google/start',[])->assertUnprocessable();
        $this->assertDatabaseCount('player_oauth_states',0);Http::assertNothingSent();
        $r=$this->postAs($who,'/api/auth/oauth/google/start',['legal'=>$this->accepted()])->assertOk();parse_str(parse_url($r->json('url'),PHP_URL_QUERY),$query);
        $this->assertDatabaseHas('player_oauth_states',['legal_version'=>$this->accepted()['version']]);
        $finished=$this->postAs($who,'/api/auth/oauth/google/finish',['state'=>$query['state'],'code'=>'valid'])->assertOk();
        $this->assertDatabaseHas('player_legal_consents',['user_id'=>$finished->json('profile.id'),'document'=>'personal-data','method'=>'google']);
        $this->assertDatabaseCount('player_legal_consents',3);
    }
    public function test_password_login_keeps_id_progress_and_does_not_fabricate_new_consent(): void
    {
        $guest=$this->guest();$r=$this->postAs($guest,'/api/auth/register',['username'=>'legal_user','password'=>'Cedar!Kite9_Moon','legal'=>$this->accepted()])->assertOk();
        $owner=$r->json('profile.id');$receipt=DB::table('player_legal_consents')->where('user_id',$owner)->get()->toJson();
        DB::table('user_state')->insert(['user_id'=>$owner,'state_key'=>'wiki-test','value'=>'{"saved":true}','version'=>7,'updated_at'=>time()]);
        $other=$this->guest();$this->postAs($other,'/api/auth/login',['username'=>'legal_user','password'=>'Cedar!Kite9_Moon'])->assertOk()->assertJsonPath('profile.id',$owner);
        $this->assertSame($receipt,DB::table('player_legal_consents')->where('user_id',$owner)->get()->toJson());
        $this->assertDatabaseHas('user_state',['user_id'=>$owner,'state_key'=>'wiki-test','version'=>7,'value'=>'{"saved":true}']);
    }
    public function test_changed_documents_invalidate_oauth_before_profile_is_requested(): void
    {
        config(['player-auth.google'=>['client_id'=>'test-client','client_secret'=>'private-test']]);Http::preventStrayRequests();Http::fake();
        $who=$this->guest();$r=$this->postAs($who,'/api/auth/oauth/google/start',['legal'=>$this->accepted()])->assertOk();
        parse_str(parse_url($r->json('url'),PHP_URL_QUERY),$query);config(['legal.edition'=>'Новая редакция']);
        $this->postAs($who,'/api/auth/oauth/google/finish',['state'=>$query['state'],'code'=>'valid'])->assertUnprocessable()->assertJsonValidationErrors('legal.version');
        Http::assertNothingSent();$this->assertDatabaseCount('player_identities',0);$this->assertDatabaseCount('player_legal_consents',0);$this->assertDatabaseCount('users',1);
    }
    public function test_old_account_confirms_before_link_without_losing_progress_or_getting_fake_history(): void
    {
        config(['legal.required'=>false]);$guest=$this->guest();$r=$this->postAs($guest,'/api/auth/register',['username'=>'old_legal_user','password'=>'Cedar!Kite9_Moon'])->assertOk();
        $who=[$r->headers->getCookies()[0]->getValue(),$r->json('csrf'),$r->json('profile.id')];$this->assertDatabaseCount('player_legal_consents',0);
        DB::table('user_state')->insert(['user_id'=>$who[2],'state_key'=>'old-progress','value'=>'{"saved":true}','version'=>9,'updated_at'=>time()]);
        config(['legal.required'=>true,'player-auth.google'=>['client_id'=>'test-client','client_secret'=>'private-test']]);
        Http::preventStrayRequests();Http::fake(['oauth2.googleapis.com/token'=>Http::response(['access_token'=>'test']),
            'openidconnect.googleapis.com/v1/userinfo'=>Http::response(['sub'=>'old-google-user','name'=>'Игрок','email'=>'old@example.test'])]);
        $this->postAs($who,'/api/auth/oauth/google/start',[])->assertUnprocessable();
        $r=$this->postAs($who,'/api/auth/oauth/google/start',['legal'=>$this->accepted()])->assertOk();parse_str(parse_url($r->json('url'),PHP_URL_QUERY),$query);
        $this->assertDatabaseCount('player_legal_consents',0);
        $this->postAs($who,'/api/auth/oauth/google/finish',['state'=>$query['state'],'code'=>'valid'])->assertOk()->assertJsonPath('profile.id',$who[2]);
        $this->assertDatabaseHas('user_state',['user_id'=>$who[2],'state_key'=>'old-progress','version'=>9]);$this->assertDatabaseCount('player_legal_consents',3);
        $this->assertTrue(app(LegalConsents::class)->hasCurrent($who[2]));
    }
    public function test_legal_pages_are_public_without_creating_accounts_and_checkboxes_are_not_preselected(): void
    {
        foreach(['privacy','terms','personal-data','cookies'] as $page){$r=$this->get('/'.$page)->assertOk()->assertSee('Масленкин Роман Андреевич')->assertSee('narnesss@mail.ru');
            $r->assertDontSee('bootstrap.mjs',false);$this->assertSame([],$r->headers->getCookies());}
        $this->assertDatabaseCount('users',0);
        $html=$this->get('/account')->assertOk()->getContent();$dom=new \DOMDocument();@$dom->loadHTML($html);$xpath=new \DOMXPath($dom);
        $this->assertSame(9,$xpath->query('//fieldset[contains(@class,"legal-checkboxes")]//input[@type="checkbox" and @required and not(@checked)]')->length);
        $this->assertSame(3,$xpath->query('//fieldset[contains(@class,"legal-checkboxes") and @disabled and @hidden]')->length);
    }
}
