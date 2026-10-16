<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Models\User;
use App\Services\PlayerAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Проверяет полномочия, сохранность прогресса, CAS и полное удаление только выбранного игрока. */
final class PlayerAdminTest extends TestCase
{
    use RefreshDatabase;
    private function admin(bool $allowed=true):User
    {return User::create(['name'=>'Владелец','email'=>'owner@example.test','password'=>bcrypt('admin-test'),'permissions'=>['platform.index'=>true,'wiki.content'=>true,'wiki.users'=>$allowed]]);}
    private function input():array
    {return ['username'=>'managed_player','display_name'=>'Игрок','email'=>'','avatar_url'=>'','password'=>'Cedar!Kite9_Moon'];}
    public function test_migration_preserves_visible_profiles_and_grants_management_only_to_initial_admin():void
    {
        $first=$this->admin(false);$second=User::create(['name'=>'Редактор','email'=>'second@example.test','password'=>bcrypt('test'),'permissions'=>['platform.index'=>true,'wiki.content'=>true]]);
        $id=str_repeat('a',32);DB::table('users')->insert(['id'=>$id,'username'=>'legacy_player','password_hash'=>null,'created_at'=>time()]);
        DB::table('player_profiles')->insert(['user_id'=>$id,'display_name'=>null,'provider_name'=>'Текущее имя','avatar_url'=>'https://lh3.googleusercontent.com/kept','updated_at'=>time()]);
        $migration=require database_path('migrations/2026_10_16_000011_stabilize_player_profiles.php');$migration->up();
        $this->assertTrue($first->fresh()->hasAccess('wiki.users'));$this->assertFalse($second->fresh()->hasAccess('wiki.users'));
        $this->assertSame('Текущее имя',DB::table('player_profiles')->where('user_id',$id)->value('display_name'));
        $this->assertSame('https://lh3.googleusercontent.com/kept',DB::table('player_profiles')->where('user_id',$id)->value('avatar_url'));
        $this->assertSame($id,DB::table('users')->where('username','legacy_player')->value('id'));
    }
    public function test_editor_without_specific_permission_cannot_read_or_manage_players():void
    {
        $this->actingAs($this->admin(false));$this->get('/admin/players')->assertForbidden();
        $this->post('/admin/players/create/save',['player'=>$this->input()])->assertForbidden();
        $this->assertSame(0,DB::table('users')->count());
    }
    public function test_admin_can_create_edit_and_delete_player_without_manual_ids_or_progress_loss():void
    {
        $this->actingAs($this->admin());$input=$this->input();
        $this->get('/admin/players/create')->assertOk()->assertSee('Отображаемое имя');
        $this->post('/admin/players/create/save',['player'=>$input])->assertRedirect();
        $owner=DB::table('users')->where('username',$input['username'])->first();$this->assertNotNull($owner);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D',$owner->id);$this->assertTrue(password_verify($input['password'],$owner->password_hash));
        DB::table('user_state')->insert(['user_id'=>$owner->id,'state_key'=>'kord-achievements-pve','value'=>'["earned"]','version'=>8,'updated_at'=>time()]);
        DB::table('sessions')->insert(['user_id'=>$owner->id,'token_hash'=>str_repeat('d',64),'expires_at'=>time()+600]);
        DB::table('player_identities')->insert(['user_id'=>$owner->id,'provider'=>'google','subject'=>'managed-subject','display_name'=>'Google','created_at'=>time()]);
        $this->get('/admin/players')->assertOk()->assertSee('managed_player')->assertDontSee($owner->password_hash,false);
        $this->get('/admin/players/'.$owner->id.'/edit')->assertOk()->assertDontSee($owner->password_hash,false)->assertSee('Google');
        $input['password']='';$input['display_name']='Изменённое имя';$input['revision']=app(PlayerAdmin::class)->revision($owner->id);
        $this->post('/admin/players/'.$owner->id.'/edit/save',['player'=>$input])->assertRedirect();
        $this->assertSame($owner->password_hash,DB::table('users')->where('id',$owner->id)->value('password_hash'));
        $this->assertSame(8,(int)DB::table('user_state')->where('user_id',$owner->id)->value('version'));
        $this->assertSame(1,DB::table('sessions')->where('user_id',$owner->id)->count());
        $this->assertSame('Изменённое имя',DB::table('player_profiles')->where('user_id',$owner->id)->value('display_name'));
        $this->post('/admin/players/'.$owner->id.'/edit/save',['player'=>$input])->assertConflict();
        $input['revision']=app(PlayerAdmin::class)->revision($owner->id);
        $this->post('/admin/players/'.$owner->id.'/edit/remove',['player'=>$input])->assertRedirect('/admin/players');
        foreach(['users'=>'id','sessions'=>'user_id','user_state'=>'user_id','player_profiles'=>'user_id','player_identities'=>'user_id'] as $table=>$key){$this->assertSame(0,DB::table($table)->where($key,$owner->id)->count());}
        $this->assertSame(1,User::count());
    }
    public function test_password_change_keeps_progress_and_prevents_reuse_and_contact_conflicts():void
    {
        $this->actingAs($this->admin());$editor=app(PlayerAdmin::class);$input=$this->input();$owner=$editor->save(null,$input);
        $other=$editor->save(null,[...$input,'username'=>'other_player','email'=>'taken@example.test']);
        $change=[...$input,'revision'=>$editor->revision($owner->id),'password'=>'Birch#Fawn8_River','email'=>'taken@example.test'];
        $this->postJson('/admin/players/'.$owner->id.'/edit/save',['player'=>$change])->assertUnprocessable()->assertJsonValidationErrors('player.email');
        $this->assertTrue(password_verify($input['password'],DB::table('users')->where('id',$owner->id)->value('password_hash')));
        $change['email']='';DB::table('sessions')->insert(['token_hash'=>str_repeat('f',64),'user_id'=>$owner->id,'expires_at'=>time()+600]);
        $this->post('/admin/players/'.$owner->id.'/edit/save',['player'=>$change])->assertRedirect();
        $this->assertSame(0,DB::table('sessions')->where('user_id',$owner->id)->count());$this->assertSame(1,DB::table('player_password_history')->where('user_id',$owner->id)->count());
        $reuse=[...$input,'revision'=>$editor->revision($owner->id)];$this->post('/admin/players/'.$owner->id.'/edit/save',['player'=>$reuse])->assertUnprocessable();
        $this->postJson('/admin/players/create/save',['player'=>[...$input,'username'=>'weak_player','password'=>'weak password 99']])->assertUnprocessable()->assertJsonValidationErrors('player.password')->assertDontSee('weak password 99');
        $this->assertSame(2,DB::table('users')->count());
    }
}
