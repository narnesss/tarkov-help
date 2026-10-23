<?php

declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset,Quest};
use App\Services\{ContentUpdates,WikiImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Проверяет точечный выпуск контента, повторный деплой и сохранность редакторских данных. */
final class ContentUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private function dataset(string $path,array $value): ContentDataset
    { $row=new ContentDataset(['path'=>$path]);$row->setContent($value);$row->save();return $row; }

    private function fixtures(): array
    {
        $this->dataset('maps.json',[['id'=>'ground-zero','name'=>'Эпицентр','file'=>'map.webp','width'=>1000,'height'=>1000]]);
        $this->dataset('guide/quests-pve.json',['traders'=>[]]);
        $this->dataset('guide/quest-content.json',['quests'=>['source'=>['briefing'=>['paragraphs'=>['Прежний диалог']],'rewards'=>['lines'=>['Сохранить']]]],'modes'=>['pve'=>['quest'=>'source']]]);
        $index=$this->dataset('guide/quest-map-index.json',['sourceMaps'=>[],'sourceQuests'=>[],'modes'=>['pve'=>[]]]);
        $map=$this->dataset('raid/ground-zero.json',['mapId'=>'ground-zero','pois'=>[
            ['id'=>'wrong-copy','name'=>'Чужая копия','kind'=>'extract','category'=>'extract-pmc','position'=>['x'=>1,'y'=>0,'z'=>2],'gameMode'=>'regular'],
            ['id'=>'correct-point','name'=>'Настоящая точка','kind'=>'extract','category'=>'extract-pmc','position'=>['x'=>3,'y'=>0,'z'=>4],'gameMode'=>'regular'],
        ]]);
        $payload=['id'=>'quest','name'=>'Квест','description'=>'Редакторский текст','trader'=>['id'=>'prapor','name'=>'Прапор'],
            'objectives'=>[['id'=>'stable-goal','type'=>'visit','description'=>'Прежняя цель','maps'=>[],'optional'=>true,'count'=>3,'cmsConditionGroups'=>[]]],
            'questContent'=>['briefing'=>['paragraphs'=>['Прежний диалог']]],'finishRewards'=>['items'=>[['item'=>'stable-item','count'=>2]]]];
        $quest=Quest::create(['quest_id'=>'quest','mode'=>'pve','name'=>'Квест','published'=>true,'payload'=>$payload]);
        app(WikiImporter::class)->rebuild();
        DB::table('users')->insert(['id'=>'player','username'=>'Игрок','password_hash'=>'unchanged-hash','created_at'=>time()]);
        DB::table('user_state')->insert(['user_id'=>'player','state_key'=>'saved-progress','value'=>'{"quest":{"objectives":["stable-goal"]}}','version'=>8,'updated_at'=>time()]);
        return [$quest,$map,$index];
    }

    private function update(): array
    {
        return ['schemaVersion'=>1,'id'=>2026101601,'quests'=>[['id'=>'quest','modes'=>['pve'],'expectedObjectiveIds'=>['stable-goal'],
            'walkthrough'=>['paragraphs'=>['Новая инструкция'],'sourceUrl'=>'https://escapefromtarkov.fandom.com/wiki/Guide'],
            'gallery'=>[['url'=>'assets/guide.webp','originalUrl'=>'https://example.test/image','objectiveIds'=>['stable-goal']]],
            'objectiveGuides'=>['stable-goal'=>['description'=>'Уточнённая цель','maps'=>['ground-zero']]]]],
            'datasets'=>['guide/quest-map-index.json'=>['sourceMaps'=>['guide'=>['src'=>'assets/map.webp','width'=>1000,'height'=>1000]],
                'sourceQuests'=>['quest'=>['guide'=>[['x'=>50,'y'=>50,'objectiveId'=>'stable-goal']]]],
                'modes'=>['pve'=>['quest'=>[['id'=>'ground-zero-guide','renderer'=>'source','sourceKey'=>'guide','points'=>[['objectiveId'=>'stable-goal']]]]]]],
                'raid/ground-zero.json'=>['archivePoints'=>['wrong-copy'],'reason'=>'Другая локация'],
                'guide/quest-content.json'=>['quests'=>['source'=>['walkthrough'=>['paragraphs'=>['Новая инструкция']]]]]]];
    }

    public function test_raid_coordinate_patch_preserves_ids_links_and_rejects_editor_conflicts(): void
    {
        [, $map]=$this->fixtures();$pointId=DB::table('wiki_map_points')->where('external_id','correct-point')->value('id');
        $state=DB::table('user_state')->first();
        $patch=['schemaVersion'=>1,'id'=>2026102098,'datasets'=>['raid/ground-zero.json'=>['raidPointRecords'=>[
            ['id'=>'correct-point','expected'=>['position'=>['x'=>3,'y'=>0,'z'=>4]],'fields'=>['position'=>['x'=>0,'y'=>5,'z'=>8]]],
            ['id'=>'individual','expected'=>null,'fields'=>['category'=>'spawn-pmc','kind'=>'spawn','name'=>'Спавн ЧВК','gameMode'=>'regular','sourceMapId'=>'ground-zero','position'=>['x'=>9,'y'=>0,'z'=>5]]],
            ['id'=>'wrong-copy','expected'=>['position'=>['x'=>1,'y'=>0,'z'=>2]],'fields'=>['cmsArchived'=>true]],
        ]]]];
        $ref=$map->getRawOriginal('payload');app(ContentUpdates::class)->apply($patch,true);$this->assertSame($ref,$map->fresh()->getRawOriginal('payload'));
        app(ContentUpdates::class)->apply($patch);
        $this->assertEquals(0,DB::table('wiki_point_world_positions')->where('point_id',$pointId)->where('archived',0)->value('world_x'));
        $this->assertSame($pointId,DB::table('wiki_map_points')->where('external_id','correct-point')->value('id'));
        $this->assertDatabaseHas('wiki_map_points',['external_id'=>'wrong-copy','archived'=>1]);
        $this->assertDatabaseHas('wiki_map_points',['external_id'=>'individual','archived'=>0]);
        $this->assertFalse(app(ContentUpdates::class)->apply($patch)['applied']);$this->assertEquals($state,DB::table('user_state')->first());
        $patch['id']++;$patch['datasets']['raid/ground-zero.json']['raidPointRecords'][0]['fields']['position']['x']=50;
        $ref=$map->fresh()->getRawOriginal('payload');
        try{app(ContentUpdates::class)->apply($patch);$this->fail('Ожидался конфликт координат');}catch(\RuntimeException $e){$this->assertStringContainsString('Запись уже изменена',$e->getMessage());}
        $this->assertSame($ref,$map->fresh()->getRawOriginal('payload'));
    }

    public function test_confirmed_spawn_becomes_visible_without_changing_ids_progress_or_editor_notes(): void
    {
        [, $map]=$this->fixtures();$data=$map->content();
        $data['pois'][1]=[...$data['pois'][1],'sourceStatus'=>'supplemental','hiddenByDefault'=>true,'sourceNote'=>'Не подтверждена'];
        $map->setContent($data);$map->save();app(WikiImporter::class)->dataset($map);
        $pointId=DB::table('wiki_map_points')->where('external_id','correct-point')->value('id');$state=DB::table('user_state')->first();
        $patch=['schemaVersion'=>1,'id'=>2026102301,'datasets'=>['raid/ground-zero.json'=>['raidPointRecords'=>[[
            'id'=>'correct-point','expected'=>['position'=>['x'=>3,'y'=>0,'z'=>4],'sourceStatus'=>'supplemental','hiddenByDefault'=>true,'sourceNote'=>'Не подтверждена'],
            'fields'=>['sourceStatus'=>'current','hiddenByDefault'=>false,'sourceNote'=>null,'coordinateSource'=>'https://tarkov.dev/map/ground-zero','coordinateDate'=>'2026-10-23'],
        ]]]]];
        $ref=$map->getRawOriginal('payload');app(ContentUpdates::class)->apply($patch,true);$this->assertSame($ref,$map->fresh()->getRawOriginal('payload'));
        $data['pois'][1]['sourceNote']='Новая заметка редактора';$map->setContent($data);$map->save();$edited=$map->getRawOriginal('payload');
        try{app(ContentUpdates::class)->apply($patch);$this->fail('Ожидался конфликт заметки');}catch(\RuntimeException $e){$this->assertStringContainsString('sourceNote',$e->getMessage());}
        $this->assertSame($edited,$map->fresh()->getRawOriginal('payload'));$this->assertDatabaseMissing('wiki_projection_states',['owner_type'=>'content-update','owner_id'=>2026102301]);
        $data['pois'][1]['sourceNote']='Не подтверждена';$map->setContent($data);$map->save();app(WikiImporter::class)->dataset($map);
        app(ContentUpdates::class)->apply($patch);
        $this->getJson('/api/content/data/raid/ground-zero.json')->assertOk()->assertJsonPath('pois.1.id','correct-point')
            ->assertJsonPath('pois.1.hiddenByDefault',false)->assertJsonPath('pois.1.sourceStatus','current')->assertJsonPath('pois.1.sourceNote',null);
        $this->assertSame($pointId,DB::table('wiki_map_points')->where('external_id','correct-point')->value('id'));
        $this->assertEquals($state,DB::table('user_state')->first());$this->assertFalse(app(ContentUpdates::class)->apply($patch)['applied']);
    }

    public function test_additive_item_catalog_preserves_editor_values_and_resolves_new_quest_links(): void
    {
        [$quest]=$this->fixtures();$dataset=ContentDataset::where('path','guide/quests-pve.json')->firstOrFail();
        $dataset->setContent(['traders'=>[],'items'=>['editor-item'=>['id'=>'editor-item','name'=>'Имя редактора']]]);$dataset->save();
        $items=$this->dataset('guide/items-pve.json',['items'=>[['id'=>'editor-item','name'=>'Имя редактора']]]);
        $payload=$quest->payload;unset($payload['objectives'][0]['cmsConditionGroups']);$payload['objectives'][0]['items']=['event-item'];$quest->payload=$payload;$quest->save();
        // На production полный импорт перед пакетами уже согласовал прежние файловые версии.
        app(WikiImporter::class)->dataset($dataset->fresh());app(WikiImporter::class)->dataset($items->fresh());
        $state=DB::table('user_state')->first();$ref=$dataset->fresh()->getRawOriginal('payload');
        $update=$this->update();$update['datasets']['guide/quests-pve.json']=['appendReferences'=>['items'=>[
            ['id'=>'editor-item','name'=>'Устаревшее исходное имя'],['id'=>'event-item','name'=>'Новый событийный предмет'],
        ]]];
        $update['datasets']['guide/items-pve.json']=$update['datasets']['guide/quests-pve.json'];
        app(ContentUpdates::class)->apply($update,true);$this->assertSame($ref,$dataset->fresh()->getRawOriginal('payload'));
        // Повторный импорт прежних предметов раздувал расход памяти и число SQL-запросов на сервере.
        $guard=true;DB::listen(function($query)use(&$guard): void {
            if($guard&&str_contains($query->sql,'wiki_items')&&in_array('editor-item',$query->bindings,true)){throw new \RuntimeException('Нельзя переимпортировать прежний справочник');}
        });
        try{app(ContentUpdates::class)->apply($update);}finally{$guard=false;}
        $this->assertSame('Имя редактора',$dataset->fresh()->content()['items']['editor-item']['name']);
        $this->assertSame('Имя редактора',$items->fresh()->content()['items'][0]['name']);
        $this->getJson('/api/content/data/guide/items-pve.json')->assertOk()->assertJsonCount(2,'items')->assertJsonPath('items.1.id','event-item');
        $this->assertStringStartsWith('@file:',$items->fresh()->getRawOriginal('payload'));
        $item=DB::table('wiki_items')->where('external_id','event-item')->value('id');$this->assertNotNull($item);
        $objective=DB::table('wiki_objectives')->where('external_id','stable-goal')->value('id');
        $group=DB::table('wiki_objective_groups')->where('objective_id',$objective)->where('source_path','/items')->value('id');
        $this->assertDatabaseHas('wiki_objective_items',['group_id'=>$group,'item_id'=>$item,'archived'=>0]);
        $this->assertEquals($state,DB::table('user_state')->first());
        $this->assertFalse(app(ContentUpdates::class)->apply($update)['applied']);
        $this->assertSame(1,DB::table('wiki_items')->where('external_id','event-item')->count());
    }

    public function test_additive_item_catalog_rejects_non_item_fields_before_writing(): void
    {
        $this->fixtures();$dataset=ContentDataset::where('path','guide/quests-pve.json')->firstOrFail();$ref=$dataset->getRawOriginal('payload');
        $update=$this->update();$update['datasets']['guide/quests-pve.json']=['appendReferences'=>['tasks'=>[['id'=>'replace-quest','name'=>'Подмена']]]];
        try{app(ContentUpdates::class)->apply($update);$this->fail('Изменение заданий через справочник запрещено');}
        catch(\RuntimeException $error){$this->assertStringContainsString('Недопустимый справочник',$error->getMessage());}
        $this->assertSame($ref,$dataset->fresh()->getRawOriginal('payload'));
        $this->assertDatabaseMissing('wiki_projection_states',['owner_type'=>'content-update','owner_id'=>$update['id']]);
    }

    public function test_document_atlas_keeps_point_ids_links_and_progress_and_refreshes_cached_public_data(): void
    {
        $this->fixtures();$this->dataset('docs.json',[['id'=>'technical','name'=>'Чертежи']]);
        $spawns=$this->dataset('spawns.json',[
            ['id'=>'kept','map'=>'ground-zero','doc'=>'technical','caption'=>'Комната','x'=>10,'y'=>20,
                'cmsPositions'=>[['map'=>['slug'=>'documents','name'=>'Старая схема','image_url'=>'map.webp','width'=>1000,'height'=>1000],
                    'version'=>['code'=>'current'],'layer'=>null,'x_percent'=>10,'y_percent'=>20,'order_index'=>0]],
                'cmsQuestLinks'=>[['questId'=>'quest','objectiveId'=>'stable-goal','mode'=>'pve']]],
            ['id'=>'retired','map'=>'ground-zero','doc'=>'technical','caption'=>'Старая копия','x'=>11,'y'=>21],
        ]);app(WikiImporter::class)->dataset($spawns);
        $ids=DB::table('wiki_map_points')->whereIn('external_id',['kept','retired'])->pluck('id','external_id')->all();
        $position=DB::table('wiki_point_positions')->where('point_id',$ids['kept'])->value('id');
        $link=DB::table('wiki_objective_points')->where('point_id',$ids['kept'])->first();$progress=DB::table('user_state')->first();
        $before=$this->getJson('/api/content/data/spawns.json')->assertOk()->headers->get('X-Wiki-Content-Revision');
        $patch=['schemaVersion'=>1,'id'=>2026101602,'datasets'=>['spawns.json'=>['records'=>[
            ['id'=>'kept','expected'=>['x'=>10,'y'=>20],'fields'=>['x'=>30,'y'=>40,'floor'=>'floor-1','cmsPositions'=>null]],
            ['id'=>'retired','expected'=>['x'=>11,'y'=>21],'fields'=>['cmsArchived'=>true]],
            ['id'=>'new','expected'=>null,'fields'=>['map'=>'ground-zero','doc'=>'technical','caption'=>'Новое место','x'=>50,'y'=>60]],
        ]]]];
        $this->assertFalse(app(ContentUpdates::class)->apply($patch,true)['applied']);
        $this->assertSame(10,$spawns->fresh()->content()[0]['x']);
        app(ContentUpdates::class)->apply($patch);
        $this->assertSame($position,DB::table('wiki_point_positions')->where('point_id',$ids['kept'])->value('id'));
        $this->assertDatabaseHas('wiki_point_positions',['id'=>$position,'x_percent'=>30,'y_percent'=>40,'archived'=>0]);
        $this->assertDatabaseHas('wiki_map_points',['id'=>$ids['retired'],'external_id'=>'retired','archived'=>1]);
        $currentLink=DB::table('wiki_objective_points')->where('point_id',$ids['kept'])->first();
        foreach(['objective_id','point_id','archived'] as $field){$this->assertSame($link->$field,$currentLink->$field);}
        $this->assertEquals($progress,DB::table('user_state')->first());
        $response=$this->getJson('/api/content/data/spawns.json')->assertOk()->assertJsonCount(2)->assertJsonPath('0.x',30);
        $this->assertNotSame($before,$response->headers->get('X-Wiki-Content-Revision'));
        $this->getJson('/api/content/data/spawns.json')->assertHeader('X-Wiki-Content-Cache','HIT');
        app(\App\Services\WikiSync::class)->run(fn()=>app(\App\Services\WikiPublisher::class)->publish('map_points',$ids['kept']));
        $this->getJson('/api/content/data/spawns.json')->assertJsonPath('0.x',30)->assertJsonPath('0.floor','floor-1');
        $this->assertFalse(app(ContentUpdates::class)->apply($patch)['applied']);
        // Импорт актуального seed также допустим без прежнего маркера обновления.
        $fresh=$spawns->fresh();$data=$fresh->content();$data[0]['cmsPositions']=null;$fresh->setContent($data);$fresh->save();
        $patch['id']=2026101603;app(ContentUpdates::class)->apply($patch);
    }

    public function test_document_atlas_does_not_overwrite_concurrent_editor_changes(): void
    {
        $this->fixtures();$maps=ContentDataset::where('path','maps.json')->firstOrFail();$before=$maps->getRawOriginal('payload');
        $this->dataset('spawns.json',[['id'=>'edited','map'=>'ground-zero','caption'=>'Правка редактора','x'=>5,'y'=>10]]);
        $patch=['schemaVersion'=>1,'id'=>2026101602,'datasets'=>[
            'maps.json'=>['records'=>[['id'=>'ground-zero','expected'=>['file'=>'map.webp'],'fields'=>['file'=>'new.svg']]]],
            'spawns.json'=>['records'=>[['id'=>'edited','expected'=>['x'=>1],'fields'=>['x'=>30]]]],
        ]];
        try{app(ContentUpdates::class)->apply($patch);$this->fail('Правку редактора нельзя затирать');}
        catch(\RuntimeException $error){$this->assertStringContainsString('уже изменена редактором',$error->getMessage());}
        $this->assertSame($before,$maps->fresh()->getRawOriginal('payload'));
        $this->assertDatabaseMissing('wiki_projection_states',['owner_type'=>'content-update','owner_id'=>2026101602]);
    }

    public function test_switchable_document_artwork_changes_only_file_metadata(): void
    {
        $this->fixtures();$this->dataset('spawns.json',[['id'=>'stable-document','map'=>'ground-zero','caption'=>'Документ','x'=>12.5,'y'=>34.5]]);
        $spawns=ContentDataset::where('path','spawns.json')->firstOrFail();$pointRef=$spawns->getRawOriginal('payload');
        $positions=DB::table('wiki_point_positions')->get()->all();$points=DB::table('wiki_map_points')->pluck('id','external_id')->all();$progress=DB::table('user_state')->first();
        $old=$this->getJson('/api/content/data/maps.json')->assertOk()->headers->get('X-Wiki-Content-Revision');
        $patch=['schemaVersion'=>1,'id'=>2026101603,'datasets'=>['maps.json'=>['records'=>[
            ['id'=>'ground-zero','expected'=>['file'=>'map.webp'],'fields'=>['layeredFile'=>'assets/documents/ground-zero.svg']],
        ]]]];app(ContentUpdates::class)->apply($patch);
        $response=$this->getJson('/api/content/data/maps.json')->assertJsonPath('0.layeredFile','assets/documents/ground-zero.svg')->assertJsonPath('0.file','map.webp');
        $this->assertNotSame($old,$response->headers->get('X-Wiki-Content-Revision'));
        $this->assertSame($pointRef,$spawns->fresh()->getRawOriginal('payload'));
        $this->assertEquals($positions,DB::table('wiki_point_positions')->get()->all());
        $this->assertSame($points,DB::table('wiki_map_points')->pluck('id','external_id')->all());$this->assertEquals($progress,DB::table('user_state')->first());
        $this->assertFalse(app(ContentUpdates::class)->apply($patch)['applied']);
    }

    public function test_update_changes_only_reviewed_content_and_keeps_ids_relations_progress_and_files(): void
    {
        [$quest,$map]=$this->fixtures();$before=$quest->payload;$pointIds=DB::table('wiki_map_points')->pluck('id','external_id')->all();
        $goalId=DB::table('wiki_objectives')->value('id');$progress=DB::table('user_state')->first();
        $this->getJson('/api/content/data/guide/quests-pve.json')->assertOk();
        $oldRevision=$this->getJson('/api/content/data/guide/quests-pve.json')->headers->get('X-Wiki-Content-Revision');
        $result=app(ContentUpdates::class)->apply($this->update());$this->assertTrue($result['applied']);
        $after=$quest->fresh()->payload;$this->assertSame($before['description'],$after['description']);$this->assertSame($before['finishRewards'],$after['finishRewards']);
        $this->assertSame($before['questContent']['briefing'],$after['questContent']['briefing']);$this->assertSame('stable-goal',$after['objectives'][0]['id']);
        foreach(['type','optional','count','cmsConditionGroups'] as $field){$this->assertSame($before['objectives'][0][$field],$after['objectives'][0][$field]);}
        $this->assertSame($quest->id,$quest->fresh()->id);$this->assertSame($goalId,DB::table('wiki_objectives')->value('id'));
        $this->assertSame($pointIds,DB::table('wiki_map_points')->pluck('id','external_id')->all());
        $this->assertDatabaseHas('wiki_map_points',['id'=>$pointIds['wrong-copy'],'archived'=>1]);
        $this->assertDatabaseHas('wiki_map_points',['id'=>$pointIds['correct-point'],'archived'=>0]);
        $this->assertEquals($progress,DB::table('user_state')->first());$this->assertDatabaseHas('users',['id'=>'player','password_hash'=>'unchanged-hash']);
        $this->assertStringStartsWith('@file:',$quest->fresh()->getRawOriginal('payload'));$this->assertStringStartsWith('@file:',$map->fresh()->payload);
        $this->getJson('/api/content/data/raid/ground-zero.json')->assertOk()->assertJsonCount(1,'pois')->assertJsonPath('pois.0.id','correct-point');
        $new=$this->getJson('/api/content/data/guide/quests-pve.json')->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS');
        $this->assertNotSame($oldRevision,$new->headers->get('X-Wiki-Content-Revision'));
        $this->getJson('/api/content/data/guide/quests-pve.json')->assertOk()->assertHeader('X-Wiki-Content-Cache','HIT');
    }

    public function test_dry_run_is_read_only_and_repeat_deploy_keeps_later_editor_changes(): void
    {
        [$quest,$map]=$this->fixtures();$ref=$quest->getRawOriginal('payload');$mapRef=$map->payload;
        $this->assertFalse(app(ContentUpdates::class)->apply($this->update(),true)['applied']);
        $this->assertSame($ref,$quest->fresh()->getRawOriginal('payload'));$this->assertSame($mapRef,$map->fresh()->payload);
        $this->assertDatabaseMissing('wiki_projection_states',['owner_type'=>'content-update']);
        app(ContentUpdates::class)->apply($this->update());$quest=$quest->fresh();$payload=$quest->payload;$payload['questContent']['walkthrough']['paragraphs']=['Поздняя редакторская правка'];$quest->payload=$payload;$quest->save();
        $ref=$quest->fresh()->getRawOriginal('payload');$this->assertFalse(app(ContentUpdates::class)->apply($this->update())['applied']);
        $this->assertSame($ref,$quest->fresh()->getRawOriginal('payload'));$this->assertCount(1,$quest->fresh()->payload['gallery']);
    }

    public function test_missing_goal_rolls_back_whole_update_and_applied_manifest_cannot_change(): void
    {
        [$quest,$map]=$this->fixtures();$ref=$quest->getRawOriginal('payload');$update=$this->update();$update['quests'][0]['expectedObjectiveIds']=['wrong'];
        try{app(ContentUpdates::class)->apply($update);$this->fail('Нельзя менять цели');}catch(\RuntimeException $error){$this->assertStringContainsString('Цели квеста изменились',$error->getMessage());}
        $this->assertSame($ref,$quest->fresh()->getRawOriginal('payload'));$this->assertEmpty($map->fresh()->content()['pois'][0]['cmsArchived']??false);
        $this->assertDatabaseMissing('wiki_projection_states',['owner_type'=>'content-update']);
        app(ContentUpdates::class)->apply($this->update());$update=$this->update();$update['quests'][0]['walkthrough']['paragraphs']=['Подмена'];
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('Применённый пакет нельзя менять');app(ContentUpdates::class)->apply($update);
    }
    public function test_unconfirmed_point_returns_after_editor_corrects_coordinates(): void
    {
        [$quest,$map]=$this->fixtures();$update=$this->update();$update['datasets']['raid/ground-zero.json']['pointReviews']=[[
            'id'=>'correct-point','expectedPosition'=>['x'=>3,'y'=>0,'z'=>4],'reason'=>'Проверка исходных координат']];
        app(ContentUpdates::class)->apply($update);
        $this->getJson('/api/content/data/raid/ground-zero.json')->assertOk()->assertJsonCount(0,'pois');
        $point=(int)DB::table('wiki_map_points')->where('external_id','correct-point')->value('id');
        app(\App\Services\WikiSync::class)->run(fn()=>app(\App\Services\WikiPublisher::class)->publish('map_points',$point));
        $this->getJson('/api/content/data/raid/ground-zero.json')->assertOk()->assertJsonCount(0,'pois');
        DB::table('wiki_point_world_positions')->where('point_id',$point)->update(['world_x'=>5]);
        app(\App\Services\WikiSync::class)->run(fn()=>app(\App\Services\WikiPublisher::class)->publish('map_points',$point));
        $record=collect($map->fresh()->content()['pois'])->firstWhere('id','correct-point');
        $this->assertSame('editor',$record['sourceStatus']);$this->assertArrayNotHasKey('reviewReason',$record);$this->assertArrayNotHasKey('hiddenByDefault',$record);
        $this->getJson('/api/content/data/raid/ground-zero.json')->assertOk()->assertJsonCount(1,'pois')->assertJsonPath('pois.0.id','correct-point');
        $this->assertDatabaseHas('wiki_map_points',['id'=>$point,'external_id'=>'correct-point']);
    }

    public function test_map_local_ids_are_separated_without_changing_old_ids_links_or_public_ids(): void
    {
        [$quest,$ground]=$this->fixtures();
        $maps=ContentDataset::where('path','maps.json')->first();$data=$maps->content();$data[]=['id'=>'shoreline','name'=>'Берег','file'=>'shore.webp','width'=>1000,'height'=>1000];$maps->setContent($data);$maps->save();
        $importer=app(WikiImporter::class);$importer->dataset($maps);
        $original=(int)DB::table('wiki_map_points')->where('external_id','correct-point')->value('id');
        $before=DB::table('wiki_map_points')->where('id',$original)->first();
        $goal=(int)DB::table('wiki_objectives')->value('id');
        DB::table('wiki_objective_points')->insert(['objective_id'=>$goal,'point_id'=>$original]);
        $other=ContentDataset::withoutEvents(fn()=>$this->dataset('raid/shoreline.json',['mapId'=>'shoreline','pois'=>[['id'=>'correct-point','name'=>'Другой контейнер','category'=>'loot','position'=>['x'=>50,'y'=>1,'z'=>70]]]]));
        // Восстанавливаем прежнее ошибочное состояние: два файла ссылались на одну SQL-строку.
        DB::table('wiki_sources')->insert(['dataset_id'=>$other->id,'pointer'=>'/pois/0','point_id'=>$original,'format'=>'raid-point']);
        $this->assertSame(1,$importer->repairPointScopes());
        $separate=(int)DB::table('wiki_sources')->where('dataset_id',$other->id)->value('point_id');
        $this->assertNotSame($original,$separate);$this->assertEquals($before,DB::table('wiki_map_points')->where('id',$original)->first());
        $this->assertDatabaseHas('wiki_objective_points',['objective_id'=>$goal,'point_id'=>$original,'archived'=>0]);
        $this->assertSame('shoreline',DB::table('wiki_locations')->where('id',DB::table('wiki_map_points')->where('id',$separate)->value('location_id'))->value('slug'));
        $groundRef=$ground->fresh()->payload;
        DB::table('wiki_map_points')->where('id',$separate)->update(['title'=>'Редакторская правка только Берега']);
        app(\App\Services\WikiSync::class)->run(fn()=>app(\App\Services\WikiPublisher::class)->publish('map_points',$separate));
        $this->assertSame($groundRef,$ground->fresh()->payload);
        $point=$other->fresh()->content()['pois'][0];$this->assertSame('correct-point',$point['id']);$this->assertSame('Редакторская правка только Берега',$point['name']);
        $this->assertSame(0,$importer->repairPointScopes());
        $this->assertDatabaseHas('wiki_map_points',['id'=>$separate,'title'=>'Редакторская правка только Берега']);
        $importer->dataset($other,true);$importer->dataset($ground,true);
        $this->assertSame(2,DB::table('wiki_map_points')->whereIn('id',[$original,$separate])->count());
        $this->assertSame($original,(int)DB::table('wiki_sources')->where('dataset_id',$ground->id)->where('pointer','/pois/1')->value('point_id'));
        $this->assertSame($separate,(int)DB::table('wiki_sources')->where('dataset_id',$other->id)->value('point_id'));
    }

    public function test_scope_repair_resumes_after_a_later_batch_fails(): void
    {
        [$quest,$ground]=$this->fixtures();$maps=ContentDataset::where('path','maps.json')->first();$data=$maps->content();$data[]=['id'=>'shoreline','name'=>'Берег','file'=>'shore.webp','width'=>1000,'height'=>1000];$maps->setContent($data);$maps->save();app(WikiImporter::class)->dataset($maps);
        $points=[];for($i=0;$i<105;$i++){$points[]=['id'=>'scope-'.$i,'name'=>'Контейнер '.$i,'category'=>'loot','position'=>['x'=>$i,'y'=>0,'z'=>1]];}
        $ground->setContent(['mapId'=>'ground-zero','pois'=>$points]);$ground->save();app(WikiImporter::class)->dataset($ground);
        $old=DB::table('wiki_map_points')->where('external_id','like','scope-%')->pluck('location_id','id')->all();
        $other=ContentDataset::withoutEvents(fn()=>$this->dataset('raid/shoreline.json',['mapId'=>'shoreline','pois'=>$points]));
        $fail=true;DB::listen(function($query)use(&$fail,$other): void {
            if($fail&&str_starts_with(strtolower($query->sql),'insert')&&str_contains($query->sql,'wiki_sources')&&in_array('/pois/101',$query->bindings,true)&&in_array($other->id,$query->bindings,true)){$fail=false;throw new \RuntimeException('Тестовый обрыв следующего пакета');}
        });
        try{app(WikiImporter::class)->repairPointScopes();$this->fail('Ожидался обрыв пакета');}catch(\RuntimeException $error){$this->assertStringContainsString('Тестовый обрыв',$error->getMessage());}
        $this->assertSame(100,DB::table('wiki_map_points')->where('external_id','like','scoped-%')->count());
        $this->assertSame(5,app(WikiImporter::class)->repairPointScopes());
        $this->assertSame(105,DB::table('wiki_sources')->where('dataset_id',$other->id)->count());
        $this->assertSame($old,DB::table('wiki_map_points')->whereIn('id',array_keys($old))->pluck('location_id','id')->all());
    }
    private function exitCorrectionFixtures(): array
    {
        $this->fixtures();$update=json_decode(file_get_contents(database_path('content-updates/20261020-map-exits.json')),true,flags:JSON_THROW_ON_ERROR);
        $maps=ContentDataset::where('path','maps.json')->firstOrFail();$data=$maps->content();
        foreach(['shoreline','lighthouse','streets-of-tarkov'] as $map)$data[]=['id'=>$map,'name'=>$map,'file'=>'map.webp','width'=>1000,'height'=>1000];
        $maps->setContent($data);$maps->save();
        foreach($update['datasets'] as $path=>$patch){$raw=json_decode(gzdecode(file_get_contents(public_path('data/'.$path.'.gz'))),true,flags:JSON_THROW_ON_ERROR);
            $ids=array_column($patch['pointChanges'],'id');$kept=false;$raw['pois']=array_values(array_filter($raw['pois'],function($point)use($ids,&$kept){if(in_array($point['id'],$ids,true))return true;if(!$kept){$kept=true;return true;}return false;}));
            // Актуальный seed уже включает архив; регрессия проверяет переход со старой версии.
            foreach($patch['pointChanges'] as $change){foreach($raw['pois'] as &$point){if($point['id']===$change['id']){
                foreach(array_keys($change['fields']) as $field){unset($point[$field]);}$point=[...$point,...$change['expected']];
            }}unset($point);}
            $this->dataset($path,$raw);
        }app(WikiImporter::class)->rebuild();return $update;
    }
    public function test_exit_corrections_preserve_ids_coordinates_progress_and_update_public_cache(): void
    {
        $update=$this->exitCorrectionFixtures();$before=[];foreach(ContentDataset::all() as $row)$before[$row->path]=$row->content();
        $ids=DB::table('wiki_map_points')->orderBy('id')->pluck('external_id','id')->all();$positions=DB::table('wiki_point_world_positions')->select('id','point_id','world_x','world_y','world_z','order_index')->orderBy('id')->get()->toJson();$progress=DB::table('user_state')->get()->toJson();
        $url='/api/content/data/raid/pve/shoreline.json';$revision=$this->getJson($url)->assertOk()->headers->get('X-Wiki-Content-Revision');
        $this->assertTrue(app(ContentUpdates::class)->apply($update)['applied']);$this->assertFalse(app(ContentUpdates::class)->apply($update)['applied']);
        foreach($update['datasets'] as $path=>$patch){$row=ContentDataset::where('path',$path)->firstOrFail();$after=$row->content();$expected=$before[$path];
            foreach($patch['pointChanges'] as $change){foreach($expected['pois'] as &$point){if($point['id']===$change['id'])$point=[...$point,...$change['fields']];}unset($point);}
            $this->assertSame($expected,$after);
        }
        $this->assertSame($ids,DB::table('wiki_map_points')->orderBy('id')->pluck('external_id','id')->all());
        $this->assertSame($positions,DB::table('wiki_point_world_positions')->select('id','point_id','world_x','world_y','world_z','order_index')->orderBy('id')->get()->toJson());$this->assertSame($progress,DB::table('user_state')->get()->toJson());
        $response=$this->getJson($url)->assertOk();$this->assertNotSame($revision,$response->headers->get('X-Wiki-Content-Revision'));
        $names=array_column($response->json('pois'),'name');$this->assertContains('Тропа альпиниста',$names);foreach(['Спуск со скалы','Проход через скалы','Временный КПП'] as $name)$this->assertNotContains($name,$names);
    }
    public function test_exit_patch_rejects_changed_editor_record_without_partial_writes(): void
    {
        $update=$this->exitCorrectionFixtures();$path=array_key_first($update['datasets']);$row=ContentDataset::where('path',$path)->firstOrFail();$data=$row->content();
        foreach($data['pois'] as &$point){if($point['id']===$update['datasets'][$path]['pointChanges'][0]['id'])$point['name']='Редакторское исправление';}unset($point);$row->setContent($data);$row->save();
        $refs=ContentDataset::orderBy('path')->pluck('payload')->all();
        try{app(ContentUpdates::class)->apply($update);$this->fail('Изменённая запись должна остановить пакет');}catch(\RuntimeException $error){$this->assertStringContainsString('Точка уже изменена',$error->getMessage());}
        $this->assertSame($refs,ContentDataset::orderBy('path')->pluck('payload')->all());$this->assertDatabaseMissing('wiki_projection_states',['owner_type'=>'content-update','owner_id'=>$update['id']]);
    }

    public function test_customs_door_packages_restore_key_relations_and_reject_editor_bypass_atomically(): void
    {
        $this->dataset('maps.json',[['id'=>'customs','name'=>'Таможня']]);
        $keys=['6aa2f73005eae7bedb0ad83e'=>'Ключ от поста охраны','6aa2f6565d82e97ee9076820'=>'Ключ ЗБ-1013'];
        $this->dataset('raid/item-names.json',$keys);$rows=[];$packets=[];
        foreach(['regular','pve'] as $mode){
            $packet=json_decode(file_get_contents(database_path('content-updates/20261023-map-customs-'.$mode.'.json')),true,flags:JSON_THROW_ON_ERROR);
            $path='raid/'.($mode==='pve'?'pve/':'').'customs.json';
            $patches=array_values(array_filter($packet['datasets'][$path]['raidPointRecords'],static fn($record)=>isset($record['fields']['keyIds'])));
            $this->assertCount(2,$patches);$points=[];
            foreach($patches as $patch){$points[]=['id'=>$patch['id'],'kind'=>'locked-door','category'=>'locked-door','name'=>'Дверь','gameMode'=>$mode,...$patch['expected']];}
            $rows[$mode]=$this->dataset($path,['mapId'=>'customs','pois'=>$points]);
            $packet['datasets']=[$path=>['raidPointRecords'=>$patches]];$packets[$mode]=$packet;
        }
        app(WikiImporter::class)->rebuild();
        $ids=DB::table('wiki_map_points')->orderBy('id')->get(['id','external_id','mode'])->toJson();
        $positions=DB::table('wiki_point_world_positions')->orderBy('id')->get(['id','point_id','world_x','world_y','world_z','order_index'])->toJson();
        foreach(['regular','pve'] as $mode){
            $url='/api/content/data/raid/'.($mode==='pve'?'pve/':'').'customs.json';
            $old=$this->getJson($url)->assertOk()->assertJsonPath('pois.0.keyIds',[]);
            $row=$rows[$mode];$original=$row->content();$edited=$original;
            $edited['pois'][1]['cmsKeyUsages']=[['item'=>array_key_first($keys),'usage_type'=>'bypass','notes'=>'Правка редактора']];
            $row->setContent($edited);$row->save();$ref=$row->getRawOriginal('payload');
            try{app(ContentUpdates::class)->apply($packets[$mode]);$this->fail('Редакторский обход должен остановить пакет');}
            catch(\RuntimeException $error){$this->assertStringContainsString('cmsKeyUsages',$error->getMessage());}
            $this->assertSame($ref,$row->fresh()->getRawOriginal('payload'));
            $this->assertSame([],$row->fresh()->content()['pois'][0]['keyIds']);
            $this->assertDatabaseMissing('wiki_projection_states',['owner_type'=>'content-update','owner_id'=>$packets[$mode]['id']]);
            $row->setContent($original);$row->save();
            $this->assertTrue(app(ContentUpdates::class)->apply($packets[$mode])['applied']);
            $this->assertFalse(app(ContentUpdates::class)->apply($packets[$mode])['applied']);
            $new=$this->getJson($url)->assertOk();
            $this->assertNotSame($old->headers->get('X-Wiki-Content-Revision'),$new->headers->get('X-Wiki-Content-Revision'));
            foreach($packets[$mode]['datasets'] as $dataset)foreach($dataset['raidPointRecords'] as $index=>$patch){
                $new->assertJsonPath('pois.'.$index.'.keyIds',$patch['fields']['keyIds']);
                $point=DB::table('wiki_map_points')->where('external_id',$patch['id'])->where('mode',$mode)->value('id');
                $item=DB::table('wiki_items')->where('external_id',$patch['fields']['keyIds'][0])->value('id');
                $this->assertDatabaseHas('wiki_key_usages',['point_id'=>$point,'key_item_id'=>$item,'usage_type'=>'unlock','archived'=>0]);
            }
        }
        $this->assertSame($ids,DB::table('wiki_map_points')->orderBy('id')->get(['id','external_id','mode'])->toJson());
        $this->assertSame($positions,DB::table('wiki_point_world_positions')->orderBy('id')->get(['id','point_id','world_x','world_y','world_z','order_index'])->toJson());
    }

}
