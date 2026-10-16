<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset,Quest,User};
use App\Services\{AdminInput,WikiSchema,WikiImporter,ContentFields};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Проверяет вредоносный ввод только на изолированных SQLite/MariaDB и временных файлах. */
final class AdminContentSecurityTest extends TestCase
{
    use RefreshDatabase;
    private function fixtures(): Quest
    {
        foreach(['maps.json'=>[['id'=>'lighthouse','name'=>'Маяк','file'=>'/map.svg','width'=>1000,'height'=>800]],
            'docs.json'=>[['id'=>'doc','name'=>'Письмо']],
            'guide/items-pve.json'=>['items'=>[['id'=>'watch','name'=>'Часы'],['id'=>'key','name'=>'Ключ']]],
            'guide/quests-pve.json'=>['traders'=>[['id'=>'prapor','name'=>'Прапор']]],
            'spawns.json'=>[['id'=>'point','map'=>'lighthouse','doc'=>'doc','caption'=>'Коттедж','x'=>10,'y'=>20,'keyIds'=>['key'],'itemIds'=>['watch']]],
        ] as $path=>$data){$dataset=new ContentDataset(['path'=>$path]);$dataset->setContent($data);$dataset->save();}
        $quest=Quest::create(['quest_id'=>'quest-original','mode'=>'pve','name'=>'Поиск часов','trader_id'=>'prapor','published'=>false,
            'payload'=>['id'=>'quest-original','name'=>'Поиск часов','trader'=>['id'=>'prapor','name'=>'Прапор'],'objectives'=>[['id'=>'goal-original','type'=>'findItem','description'=>'Найти часы']], 'questContent'=>['walkthrough'=>'Обычный текст','gallery'=>['/image.png']]]]);
        app(WikiImporter::class)->rebuild();
        $this->actingAs(User::create(['name'=>'Редактор','email'=>'security@example.test','password'=>bcrypt('test'),'permissions'=>['platform.index'=>true,'wiki.content'=>true]]));
        return $quest;
    }
    private function input(string $type): array
    {
        $schema=app(WikiSchema::class);$row=$schema->query($type)->firstOrFail();$input=['revision'=>$row->revision];
        foreach($schema->fields($type) as $field=>$column){$value=$row->$field;$foreign=$schema->foreigns($type)[$field]??null;
            if($foreign&&$value!==null){$value=$schema->query($foreign['type'])->where($foreign['column'],$value)->value('rowid');}$input[$field]=$value;}
        return [$row->rowid,$input];
    }
    private function snapshot(): string
    {
        $rows=[];foreach(['quests','content_datasets',...array_map(fn($type)=>'wiki_'.$type,array_keys(WikiSchema::TYPES))] as $table){$rows[$table]=DB::table($table)->get()->all();}
        return hash('sha256',serialize($rows));
    }
    public function test_all_four_editors_reject_xss_and_bad_types_without_partial_writes(): void
    {
        $this->fixtures();$before=$this->snapshot();
        foreach(['quests'=>'title','items'=>'name','keys'=>'description','map_points'=>'title'] as $type=>$field){
            [$id,$input]=$this->input($type);$url='/admin/wiki/'.$type.'/'.$id.'/edit/save';
            foreach(['<img src=x onerror=alert(1)>','</textarea><script>alert(1)</script>',"bad\0text",['wrong']] as $attack){
                $this->postJson($url,['row'=>[...$input,$field=>$attack]])->assertUnprocessable()->assertJsonValidationErrors('row.'.$field);
                $this->assertSame($before,$this->snapshot());
            }
            $this->postJson($url,['row'=>[...$input,'revision'=>['invalid']]])->assertUnprocessable()->assertJsonValidationErrors('row.revision');
            $this->postJson($url,['row'=>[...$input,'id'=>999]])->assertUnprocessable();
        }
        $this->assertSame($before,$this->snapshot());
    }
    public function test_urls_numbers_choices_and_relationships_are_checked_before_writes(): void
    {
        $this->fixtures();$before=$this->snapshot();[$id,$input]=$this->input('items');$url='/admin/wiki/items/'.$id.'/edit/save';
        foreach(['javascript:alert(1)','data:image/svg+xml,<svg onload=alert(1)>','//evil.test/a','https://good.test\\@evil.test/a','https://user:pass@example.test/a'] as $value){
            $this->postJson($url,['row'=>[...$input,'icon_url'=>$value]])->assertUnprocessable()->assertJsonValidationErrors('row.icon_url');
        }
            foreach(['1e999',[]] as $value){$this->postJson($url,['row'=>[...$input,'weight'=>$value]])->assertUnprocessable()->assertJsonValidationErrors('row.weight');}
        $this->postJson($url,['row'=>[...$input,'category'=>str_repeat('x',192)]])->assertUnprocessable();
        [$id,$input]=$this->input('map_points');$url='/admin/wiki/map_points/'.$id.'/edit/save';
        $this->postJson($url,['row'=>[...$input,'mode'=>['pve']]])->assertUnprocessable()->assertJsonValidationErrors('row.mode');
        $this->postJson($url,['row'=>[...$input,'location_id'=>"1 OR 1=1"]])->assertUnprocessable()->assertJsonValidationErrors('row.location_id');
        $this->assertSame($before,$this->snapshot());
    }
    public function test_text_is_trimmed_and_sql_like_text_is_saved_literally_with_ids_unchanged(): void
    {
        $quest=$this->fixtures();$questId=$quest->quest_id;$goalId=$quest->payload['objectives'][0]['id'];
        foreach(['items'=>'description','keys'=>'description','map_points'=>'description','quests'=>'description'] as $type=>$field){
            [$id,$input]=$this->input($type);$value="О'Брайен'); DROP TABLE users; --\nВторая строка";
            $this->post('/admin/wiki/'.$type.'/'.$id.'/edit/save',['row'=>[...$input,$field=>"  \n".$value." \t"]])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($value,app(WikiSchema::class)->record($type,$id)->value($field));
        }
        $this->assertSame($questId,$quest->fresh()->quest_id);$this->assertSame($goalId,$quest->fresh()->payload['objectives'][0]['id']);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('users'));
        $this->assertSame(' opaque ',AdminInput::trim(['token'=>' opaque '])['token']);
    }
    public function test_search_payloads_are_bound_escaped_and_malformed_queries_do_not_cause_500(): void
    {
        $this->fixtures();$before=$this->snapshot();
        foreach(['/admin/wiki/items?q=','/admin/wiki/keys?q=','/admin/wiki/map_points?q=','/admin/wiki/quests?q=','/admin/quests?search='] as $url){
            foreach(["' OR 1=1 --",'<svg onload=alert(1)>','%'] as $payload){$this->get($url.rawurlencode($payload))->assertOk()->assertDontSee('onload=alert(1)>',false);}
            $this->getJson($url.'x&'.(str_contains($url,'q=')?'q':'search').'[]=array')->assertUnprocessable();
        }
        $this->getJson('/admin/wiki/items?filter[]=x&parent[]=1')->assertUnprocessable();
        $this->getJson('/admin/quests?mode[]=pve')->assertUnprocessable();
        $this->getJson('/admin/wiki/items?type[]=keys')->assertUnprocessable();
        $this->getJson('/admin/wiki/items/1/edit?record=2')->assertUnprocessable();
        $this->get('/admin/wiki/items?q='.rawurlencode('  Часы  '))->assertOk()->assertSee('Часы');
        $this->assertSame($before,$this->snapshot());
    }
    public function test_legacy_quest_and_nested_fields_validate_types_html_urls_and_additions(): void
    {
        $quest=$this->fixtures();$before=$this->snapshot();$url='/admin/quests/'.$quest->id.'/fields';
        $key=hash('sha256','/questContent/walkthrough');
        $this->postJson($url.'/save?section=/questContent',['revision'=>$quest->revision(),'fields'=>[$key=>'<script>alert(1)</script>']])->assertUnprocessable()->assertJsonValidationErrors('fields.'.$key);
        $this->postJson($url.'/add?section=/questContent/gallery',['revision'=>$quest->revision(),'addition'=>['preset'=>'record','value'=>'javascript:alert(1)']])->assertUnprocessable();
        $this->postJson($url.'/add?section=/questContent/gallery',['revision'=>$quest->revision(),'fields'=>'bad','addition'=>['preset'=>'record','value'=>'/image.jpg']])->assertUnprocessable();
        $this->getJson($url.'?section[]=bad')->assertUnprocessable();
        $this->postJson('/admin/quests/create/save',['quest'=>'scalar'])->assertUnprocessable();
        $this->postJson('/admin/quests/create/save',['quest'=>['mode'=>['pve'],'goals'=>[]]])->assertUnprocessable();
        $this->assertSame($before,$this->snapshot());
        $this->post($url.'/save?section=/questContent',['revision'=>$quest->revision(),'fields'=>[$key=>"  Первая строка\nВторая строка  "]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame("Первая строка\nВторая строка",$quest->fresh()->payload['questContent']['walkthrough']);
    }
    public function test_existing_untrusted_text_is_escaped_in_editor_and_list(): void
    {
        $this->fixtures();$xss='</textarea><img src=x onerror=alert(1)>';
        foreach(['items','keys','map_points','quests'] as $type){
            [$id]=$this->input($type);$query=app(WikiSchema::class)->record($type,$id);$query->update(['display_name'=>$xss]);
            $this->get('/admin/wiki/'.$type.($type==='map_points'?'?point_kind=documents':''))->assertOk()->assertSee(e($xss),false)->assertDontSee($xss,false);
        }
        [$id]=$this->input('items');app(WikiSchema::class)->record('items',$id)->update(['description'=>$xss]);
        $this->get('/admin/wiki/items/'.$id.'/edit')->assertOk()->assertSee(e($xss),false)->assertDontSee($xss,false)->assertSee('data-admin-text',false);
    }
    public function test_relation_search_checks_parameters_and_treats_sql_as_literal(): void
    {
        $this->fixtures();$before=$this->snapshot();
        $encrypt=\Illuminate\Support\Facades\Crypt::class;
        $params=['model'=>$encrypt::encryptString(\App\Models\Wiki\Options::class),'name'=>$encrypt::encryptString('label'),
            'key'=>$encrypt::encryptString('id'),'scope'=>$encrypt::encrypt(['name'=>'picker','parameters'=>['items']]),'chunk'=>20];
        $url=route('platform.systems.relation');
        $this->postJson($url,[...$params,'search'=>'  Часы  '])->assertOk()->assertJsonPath('0.label','Часы');
        foreach(["' OR 1=1 --",'<svg onload=alert(1)>','%'] as $payload){$this->postJson($url,[...$params,'search'=>$payload])->assertOk()->assertExactJson([]);}
        foreach(['append'=>['array'],'search'=>['array'],'chunk'=>['array'],'scope'=>'tampered','unexpected'=>'bad'] as $field=>$value){
            $this->postJson($url,[...$params,$field=>$value])->assertUnprocessable();
        }
        $this->assertSame($before,$this->snapshot());
    }
    public function test_content_forms_require_permission_csrf_and_fresh_revision(): void
    {
        $this->fixtures();[$id,$input]=$this->input('items');$url='/admin/wiki/items/'.$id.'/edit/save';$before=$this->snapshot();
        $this->postJson($url,['row'=>[...$input,'revision'=>-1]])->assertUnprocessable();
        $editor=auth()->user();$editor->permissions=['platform.index'=>true,'wiki.content'=>false];$editor->save();$this->actingAs($editor->fresh());
        foreach(['quests','items','keys','map_points'] as $type){$this->postJson('/admin/wiki/'.$type.'/create/save',['row'=>[]])->assertForbidden();}
        $editor->permissions=['platform.index'=>true,'wiki.content'=>true];$editor->save();$this->actingAs($editor->fresh());
        $this->app->instance('env','production');$this->postJson($url,['row'=>$input])->assertStatus(419);
        $this->assertSame($before,$this->snapshot());
    }
}
