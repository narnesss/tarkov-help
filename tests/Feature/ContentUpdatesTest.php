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
}
