<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset,Quest,User};
use App\Services\{WikiEditor,WikiImporter,WikiSchema,WikiStore,QuestEditor};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Проверяет предметные FK, настоящие формы и публикацию без JSON в SQLite. */
final class WikiDomainTest extends TestCase
{
    use RefreshDatabase;
    private function dataset(string $path,array $data): void
    { $model=new ContentDataset(['path'=>$path]);$model->setContent($data);$model->save(); }
    private function fixtures(): Quest
    {
        $this->dataset('maps.json',[['id'=>'lighthouse','name'=>'Маяк','file'=>'/map.svg','width'=>1000,'height'=>800],['id'=>'woods','name'=>'Лес','file'=>'/woods.svg','width'=>1000,'height'=>800]]);
        $this->dataset('docs.json',[['id'=>'document','name'=>'Письмо разведчика']]);
        $this->dataset('guide/items-pve.json',['items'=>[['id'=>'watch','name'=>'Бронзовые часы'],['id'=>'key','name'=>'Ключ от коттеджа']]]);
        $this->dataset('guide/quests-pve.json',['traders'=>[['id'=>'prapor','name'=>'Прапор']], 'maps'=>[['id'=>'game-lighthouse','normalizedName'=>'lighthouse','name'=>'Маяк']]]);
        $quest=Quest::create(['quest_id'=>'quest-original','mode'=>'pve','name'=>'Поиск часов','trader_id'=>'prapor','published'=>true,'payload'=>[
            'id'=>'quest-original','name'=>'Поиск часов','trader'=>['id'=>'prapor','name'=>'Прапор'],
            'objectives'=>[['id'=>'goal-original','type'=>'findItem','description'=>'Найти часы','count'=>1,'items'=>[['id'=>'watch','name'=>'Часы','preserve'=>'да']], 'maps'=>['game-lighthouse']]],
            'finishRewards'=>['items'=>[['item'=>'watch','count'=>1,'attributes'=>['custom'=>'сохранить']]]]]]);
        $this->dataset('spawns.json',[['id'=>'point-original','map'=>'lighthouse','doc'=>'document','caption'=>'Коттедж','x'=>12,'y'=>24,'keyIds'=>['key'],'itemIds'=>['watch']]]);
        app(WikiImporter::class)->rebuild();
        $this->actingAs(User::create(['name'=>'Редактор','email'=>'wiki@example.test','password'=>bcrypt('test'),'permissions'=>['platform.index'=>true,'wiki.content'=>true]]));
        return $quest;
    }
    private function id(string $type): int
    { return (int)app(WikiSchema::class)->query($type)->firstOrFail()->rowid; }
    private function save(string $type,?int $id,array $changes): int
    {
        $schema=app(WikiSchema::class);$input=[];
        if($id!==null){$row=$schema->record($type,$id)->firstOrFail();$input=['revision'=>$row->revision];
            foreach($schema->fields($type) as $field=>$column){$value=$row->$field;$foreign=$schema->foreigns($type)[$field]??null;
                if($foreign&&$value!==null){$value=$schema->query($foreign['type'])->where($foreign['column'],$value)->value('rowid');}$input[$field]=$value;}}
        return app(WikiEditor::class)->save($type,$id,[...$input,...$changes]);
    }
    public function test_backfill_preserves_ids_files_and_is_idempotent(): void
    {
        $quest=$this->fixtures();$refs=DB::table('content_datasets')->pluck('payload','id')->all();$ref=$quest->getRawOriginal('payload');
        $before=[];foreach(WikiSchema::TYPES as $type=>$title){$before[$type]=DB::table('wiki_'.$type)->count();}
        app(WikiImporter::class)->rebuild();
        foreach($before as $type=>$count){$this->assertSame($count,DB::table('wiki_'.$type)->count(),$type);}
        $this->assertSame($refs,DB::table('content_datasets')->pluck('payload','id')->all());$this->assertSame($ref,$quest->fresh()->getRawOriginal('payload'));
        $this->assertSame('goal-original',DB::table('wiki_objectives')->value('external_id'));\App\Services\SqlDatabaseAudit::foreignKeys(DB::connection()->getPdo());
        $this->assertSame(1,DB::table('wiki_key_usages')->count());
        $item=\App\Models\Wiki\Item::where('external_id','watch')->firstOrFail();
        $this->assertSame('Коттедж',$item->spawns->first()->title);
        $point=\App\Models\Wiki\MapPoint::firstOrFail();$this->assertSame('Ключ от коттеджа',$point->keys->first()->item->name);
        $this->assertSame('Письмо разведчика',$point->documents->first()->name);
        foreach(WikiSchema::TYPES as $type=>$title){foreach($this->app->make(WikiSchema::class)->columns($type) as $column){$this->assertStringNotContainsString('JSON',strtoupper($column->type));}}
    }
    public function test_all_catalogs_and_create_forms_render_without_manual_ids(): void
    {
        $this->fixtures();$this->get('/admin/wiki')->assertOk()->assertSee('Сущности и связи');
        foreach(WikiSchema::TYPES as $type=>$title){
            $this->get('/admin/wiki/'.$type)->assertOk();$html=$this->get('/admin/wiki/'.$type.'/create')->assertOk()->getContent();
            $dom=new \DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xpath=new \DOMXPath($dom);
            $this->assertSame(0,$xpath->query('//input[contains(@name,"_id]") and @type!="hidden"]')->length,$type);
            $this->assertSame(0,$xpath->query('//textarea[contains(@name,"json")]')->length,$type);
        }
        $html=$this->get('/admin/wiki/objective_items/create')->assertOk()->assertSee('data-controller="relation"',false)->getContent();
        $this->assertStringContainsString('Предмет',$html);
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xpath=new \DOMXPath($dom);$params=['search'=>'бронзовые'];
        $relation=$xpath->query('//div[@data-controller="relation"]')->item(1);
        foreach(['model','name','key','scope','append','search-columns','chunk'] as $key){$params[$key==='search-columns'?'searchColumns':$key]=$relation->getAttribute('data-relation-'.$key);}
        $this->postJson(route('platform.systems.relation'),$params)->assertOk()->assertJsonPath('0.label','Бронзовые часы');
    }
    public function test_orchid_state_form_saves_named_foreign_key_and_public_reward(): void
    {
        $quest=$this->fixtures();$set=$this->id('reward_sets');$set=(int)DB::table('wiki_reward_sets')->where('timing','finish')->value('id');
        $url='/admin/wiki/reward_items/create?filter=reward_set_id&parent='.$set;
        $html=$this->get($url)->assertOk()->getContent();$dom=new \DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xpath=new \DOMXPath($dom);
        $action=$xpath->query('//button[contains(@formaction,"/save?")]')->item(0)->getAttribute('formaction');
        $state=$xpath->query('//input[@name="_state"]')->item(0)->getAttribute('value');
        $key=(int)DB::table('wiki_items')->where('external_id','key')->value('id');
        $this->post($action,['_state'=>$state,'row'=>['revision'=>0,'item_id'=>$key,'reward_set_id'=>$set,'amount'=>2,'order_index'=>1,'found_in_raid'=>1,'archived'=>0]])->assertRedirect()->assertSessionHasNoErrors();
        $rewards=$quest->fresh()->payload['finishRewards']['items'];$this->assertSame('key',$rewards[1]['item']);$this->assertEquals(2,$rewards[1]['count']);
        $this->assertSame('сохранить',$rewards[0]['attributes']['custom']);
        $this->get('/api/content/data/guide/quests-pve.json')->assertOk()->assertJsonPath('tasks.0.finishRewards.items.1.item','key');
    }
    public function test_relations_update_sqlite_and_compatible_files_and_survive_reimport(): void
    {
        $quest=$this->fixtures();$point=$this->id('map_points');$objective=$this->id('objectives');$key=(int)DB::table('wiki_items')->where('external_id','key')->value('id');
        $this->save('objective_points',null,['objective_id'=>$objective,'point_id'=>$point]);
        $this->save('objective_keys',null,['objective_id'=>$objective,'key_item_id'=>$this->id('keys'),'optional'=>0]);
        $group=$this->id('objective_groups');$this->save('objective_groups',$group,['operator'=>'and']);
        $this->save('objective_items',null,['group_id'=>$group,'item_id'=>$key,'role'=>'obtain','amount'=>2,'found_in_raid'=>1]);
        $payload=$quest->fresh()->payload;$this->assertSame('goal-original',$payload['objectives'][0]['id']);
        $this->assertSame('and',$payload['objectives'][0]['cmsConditionGroups'][0]['operator']);$this->assertSame('да',$payload['objectives'][0]['items'][0]['preserve']);
        $this->assertContains('key',$payload['objectives'][0]['requiredKeys']);$this->assertContains('point-original',$payload['objectives'][0]['mapPoints']);
        $payload['description']='Обычная текстовая правка';app(QuestEditor::class)->update($quest->fresh(),['payload'=>$payload],$quest->fresh()->revision());
        $this->assertSame('and',DB::table('wiki_objective_groups')->where('id',$group)->value('operator'));
        $this->assertSame(2,DB::table('wiki_objective_items')->where('archived',0)->count());
        $this->get('/api/content/data/wiki/points/'.$point.'.json')->assertOk()->assertHeader('Cache-Control','no-store, private')->assertJsonPath('keys.0.record.name','Ключ от коттеджа')->assertJsonPath('quests.0.title','Поиск часов');
        $this->get('/api/content/data/wiki/quests/'.$this->id('quests').'.json')->assertOk()->assertJsonPath('objectives.0.groups.0.operator','and');
        \App\Services\SqlDatabaseAudit::foreignKeys(DB::connection()->getPdo());
    }
    public function test_new_point_is_published_after_document_and_position_are_filled(): void
    {
        $this->fixtures();$point=$this->save('map_points',null,['location_id'=>$this->id('locations'),'title'=>'Новое место','description'=>'Проверка','mode'=>'all','active'=>1]);
        $this->assertFalse(DB::table('wiki_sources')->where('point_id',$point)->exists());
        $this->save('point_positions',null,['point_id'=>$point,'map_id'=>$this->id('maps'),'x_percent'=>30,'y_percent'=>40,'order_index'=>0]);
        $this->save('point_documents',null,['point_id'=>$point,'document_id'=>$this->id('documents')]);
        $this->assertTrue(DB::table('wiki_sources')->where('point_id',$point)->exists());
        $this->get('/api/content/data/spawns.json')->assertOk()->assertJsonPath('1.caption','Новое место')->assertJsonPath('1.x',30);
    }
    public function test_wrong_map_cycles_stale_versions_and_permissions_are_rejected(): void
    {
        $this->fixtures();$point=$this->id('map_points');
        try{$this->save('point_positions',null,['point_id'=>$point,'map_id'=>DB::table('wiki_maps')->where('slug','documents')->orderByDesc('id')->value('id'),'x_percent'=>1,'y_percent'=>1]);$this->fail('Чужая карта принята');}catch(ValidationException $e){$this->assertArrayHasKey('row.map_id',$e->errors());}
        $group=$this->id('objective_groups');try{$this->save('objective_groups',$group,['parent_id'=>$group]);$this->fail('Цикл принят');}catch(ValidationException $e){$this->assertArrayHasKey('row.parent_id',$e->errors());}
        try{$this->save('items',$this->id('items'),['revision'=>-1]);$this->fail('Старая версия принята');}catch(ValidationException $e){$this->assertArrayHasKey('row.revision',$e->errors());}
        auth()->user()->permissions=['platform.index'=>true,'wiki.content'=>false];auth()->user()->save();
        $this->get('/admin/wiki')->assertForbidden();$this->post('/admin/wiki/items/create/save',['row'=>['name'=>'Запрещено','category'=>'item']])->assertForbidden();
    }
    public function test_quest_cycles_wrong_modes_and_archived_choices_are_rejected(): void
    {
        $this->fixtures();$version=$this->id('versions');$first=$this->id('quests');
        $second=$this->save('quests',null,['version_id'=>$version,'mode'=>'pve','title'=>'Продолжение','description'=>'','published'=>0]);
        $group=$this->save('prerequisite_groups',null,['quest_id'=>$second,'operator'=>'and']);
        $this->save('quest_prerequisites',null,['group_id'=>$group,'prerequisite_quest_id'=>$first,'status'=>'complete']);
        $firstGroup=$this->id('prerequisite_groups');
        try{$this->save('quest_prerequisites',null,['group_id'=>$firstGroup,'prerequisite_quest_id'=>$second,'status'=>'complete']);$this->fail('Цикл квестов принят');}catch(ValidationException $e){$this->assertArrayHasKey('row.prerequisite_quest_id',$e->errors());}
        $point=$this->save('map_points',null,['location_id'=>$this->id('locations'),'title'=>'Только PVP','mode'=>'regular']);
        try{$this->save('objective_points',null,['objective_id'=>$this->id('objectives'),'point_id'=>$point]);$this->fail('Чужой режим принят');}catch(ValidationException $e){$this->assertArrayHasKey('row.point_id',$e->errors());}
        $item=$this->save('items',null,['name'=>'Архивный предмет','category'=>'item','archived'=>1]);
        try{$this->save('item_spawns',null,['point_id'=>$point,'item_id'=>$item]);$this->fail('Архивный предмет принят');}catch(ValidationException $e){$this->assertArrayHasKey('row.item_id',$e->errors());}
        $this->get('/api/content/data/wiki/quests/'.$second.'.json')->assertNotFound();
    }
    public function test_publication_failure_rolls_back_relation_and_file_reference(): void
    {
        if(DB::getDriverName()!=='sqlite'){$this->markTestSkipped('Инъекция SQLite RAISE проверяется отдельным SQLite job; MySQL DDL фиксирует внешнюю тестовую транзакцию.');}
        $quest=$this->fixtures();$ref=$quest->getRawOriginal('payload');$count=DB::table('wiki_objective_keys')->count();
        DB::statement("CREATE TRIGGER reject_wiki_publication BEFORE UPDATE ON quests BEGIN SELECT RAISE(ABORT,'test-publication-failure'); END");
        try{$this->save('objective_keys',null,['objective_id'=>$this->id('objectives'),'key_item_id'=>DB::table('wiki_keys')->value('item_id')]);$this->fail('Сбой публикации проигнорирован');}catch(ValidationException $e){$this->assertArrayHasKey('row.row',$e->errors());}
        $this->assertSame($count,DB::table('wiki_objective_keys')->count());$this->assertSame($ref,$quest->fresh()->getRawOriginal('payload'));
        DB::statement('DROP TRIGGER reject_wiki_publication');
    }
    public function test_archived_goal_is_hidden_without_deleting_progress_id_and_can_be_restored(): void
    {
        $quest=$this->fixtures();$objective=$this->id('objectives');$this->save('objectives',$objective,['archived'=>1]);
        $this->get('/api/content/data/guide/quests-pve.json')->assertOk()->assertJsonCount(0,'tasks.0.objectives');
        $this->assertSame('goal-original',$quest->fresh()->payload['objectives'][0]['id']);
        $this->save('objectives',$objective,['archived'=>0]);$this->get('/api/content/data/guide/quests-pve.json')->assertOk()->assertJsonPath('tasks.0.objectives.0.id','goal-original');
    }
    public function test_archived_reward_set_can_be_restored_from_typed_rows(): void
    {
        $quest=$this->fixtures();$set=(int)DB::table('wiki_reward_sets')->where('timing','finish')->value('id');
        $this->save('reward_sets',$set,['archived'=>1]);$this->assertSame([],$quest->fresh()->payload['finishRewards']);
        $this->save('reward_sets',$set,['archived'=>0]);$this->assertSame('watch',$quest->fresh()->payload['finishRewards']['items'][0]['item']);
        $this->assertSame('сохранить',$quest->fresh()->payload['finishRewards']['items'][0]['attributes']['custom']);
    }
    public function test_point_attributes_and_extra_positions_survive_old_text_form_save(): void
    {
        $this->fixtures();$point=$this->id('map_points');$spawn=$this->id('item_spawns');$key=$this->id('key_usages');$map=$this->id('maps');
        $this->save('item_spawns',$spawn,['amount'=>3,'spawn_chance'=>0.25,'notes'=>'На полке']);
        $this->save('key_usages',$key,['usage_type'=>'optional','notes'=>'Есть обход']);
        foreach([1,2] as $order){$this->save('point_positions',null,['point_id'=>$point,'map_id'=>$map,'x_percent'=>30+$order,'y_percent'=>40+$order,'order_index'=>$order]);}
        $dataset=ContentDataset::where('path','spawns.json')->firstOrFail();$url='/admin/datasets/'.$dataset->id.'/edit/save?section=/0';
        $this->post($url,['revision'=>$dataset->revision(),'fields'=>[hash('sha256','/0/caption')=>'Новая подпись',hash('sha256','/0/x')=>'15.5']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3,DB::table('wiki_point_positions')->where('point_id',$point)->where('archived',0)->count());
        $this->assertEquals(15.5,DB::table('wiki_point_positions')->where(['point_id'=>$point,'order_index'=>0])->value('x_percent'));
        $saved=DB::table('wiki_item_spawns')->where('rowid',$spawn)->first();$this->assertEquals(3,$saved->amount);$this->assertEquals(0.25,$saved->spawn_chance);$this->assertSame('На полке',$saved->notes);
        $this->assertSame('optional',DB::table('wiki_key_usages')->where('rowid',$key)->value('usage_type'));
        $this->assertSame('Новая подпись',DB::table('wiki_map_points')->where('id',$point)->value('title'));
        \App\Services\SqlDatabaseAudit::foreignKeys(DB::connection()->getPdo());
    }
}
