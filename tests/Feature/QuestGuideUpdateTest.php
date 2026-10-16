<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset,Quest};
use App\Services\{QuestGuideUpdate,WikiImporter,WikiSchema};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Дополнение прохождений не меняет существующие цели, связи и прогресс. */
final class QuestGuideUpdateTest extends TestCase
{
    use RefreshDatabase;
    private function dataset(string $path,array $payload): ContentDataset
    { $dataset=new ContentDataset(['path'=>$path]);$dataset->setContent($payload);$dataset->save();return $dataset; }
    private function guidePatch(): array
    {
        return ['version'=>'test-guide-v1','quests'=>['stable'=>['expectedObjectiveIds'=>['stable-goal'],
            'gallery'=>[['id'=>'new-frame','url'=>'assets/new.webp','objectiveIds'=>['stable-goal']]],
            'guideAdditions'=>[['id'=>'new-note','paragraphs'=>['Новый ориентир']]],
            'maps'=>[['id'=>'woods','renderer'=>'source','sourceKey'=>'new-map','points'=>[['x'=>25,'y'=>30,'name'=>'Круг','objectiveId'=>'stable-goal']]]]]],
            'sourceMaps'=>['new-map'=>['src'=>'assets/map.webp','width'=>100,'height'=>100,'ru'=>'Лес']]];
    }
    public function test_append_preserves_domain_rows_editor_fields_and_progress_and_is_idempotent(): void
    {
        foreach(['pve','regular'] as $mode){$this->dataset('guide/quests-'.$mode.'.json',['traders'=>[['id'=>'prapor','name'=>'Прапор']],'maps'=>[]]);}
        $payload=['id'=>'stable','name'=>'Название редактора','trader'=>'prapor',
            'objectives'=>[['id'=>'stable-goal','type'=>'custom','description'=>'Цель редактора','count'=>3,'custom'=>['keep'=>true]]],
            'finishRewards'=>['custom'=>'сохранить'],'taskRequirements'=>[],'gallery'=>[['id'=>'editor-frame','url'=>'assets/editor.webp']]];
        $quests=[];foreach(['pve','regular'] as $mode){$quests[]=Quest::create(['quest_id'=>'stable','mode'=>$mode,'name'=>'Название редактора','trader_id'=>'prapor','published'=>true,'payload'=>$payload]);}
        $index=['sourceMaps'=>['old-map'=>['src'=>'assets/old.webp']], 'sourceQuests'=>['stable'=>['old-map'=>[['x'=>5,'y'=>7,'name'=>'Старая точка']]]],
            'modes'=>['pve'=>['stable'=>[['id'=>'woods','renderer'=>'source','sourceKey'=>'old-map','points'=>[['x'=>5,'y'=>7,'name'=>'Старая точка']]]]],'regular'=>[]]];
        $dataset=$this->dataset('guide/quest-map-index.json',$index);
        app(WikiImporter::class)->rebuild();$rows=[];foreach(WikiSchema::TYPES as $type=>$title){$rows[$type]=app(WikiSchema::class)->query($type)->orderBy(app(WikiSchema::class)->identity($type))->get()->toArray();}
        DB::table('users')->insert(['id'=>'old-player','username'=>'old-player','password_hash'=>'untouched-hash','created_at'=>1]);
        DB::table('user_state')->insert(['user_id'=>'old-player','state_key'=>'quests:pve','value'=>'{"stable":{"objectives":["stable-goal"]}}','version'=>7,'updated_at'=>1]);
        $player=DB::table('users')->first();$progress=DB::table('user_state')->first();
        $service=app(QuestGuideUpdate::class);$service->apply($this->guidePatch());
        foreach($quests as $quest){$after=$quest->fresh()->payload;
            foreach(['id','name','trader','objectives','finishRewards','taskRequirements'] as $field){$this->assertSame($payload[$field],$after[$field],$field);}
            $this->assertSame(['editor-frame','new-frame'],array_column($after['gallery'],'id'));$this->assertTrue($quest->fresh()->published);
        }
        foreach($rows as $type=>$before){$this->assertEquals($before,app(WikiSchema::class)->query($type)->orderBy(app(WikiSchema::class)->identity($type))->get()->toArray(),$type);}
        $this->assertEquals($player,DB::table('users')->first());$this->assertEquals($progress,DB::table('user_state')->first());
        $map=$dataset->fresh()->content();$this->assertSame($index['sourceQuests']['stable']['old-map'],$map['sourceQuests']['stable']['old-map']);
        $this->assertCount(2,$map['modes']['pve']['stable']);$this->assertCount(1,$map['modes']['regular']['stable']);
        $refs=DB::table('quests')->pluck('payload','id')->all();$mapRef=$dataset->fresh()->payload;
        $service->apply($this->guidePatch());$this->assertSame($refs,DB::table('quests')->pluck('payload','id')->all());$this->assertSame($mapRef,$dataset->fresh()->payload);
    }
    public function test_changed_objective_set_aborts_every_content_write(): void
    {
        $quest=Quest::create(['quest_id'=>'stable','mode'=>'pve','name'=>'Квест','trader_id'=>'prapor','published'=>false,'payload'=>['id'=>'stable','objectives'=>[['id'=>'other']]]]);
        $before=$quest->getRawOriginal('payload');
        try{app(QuestGuideUpdate::class)->apply($this->guidePatch());$this->fail('Изменённый набор целей не отклонён');}catch(\RuntimeException $e){$this->assertStringContainsString('Набор целей изменился',$e->getMessage());}
        $this->assertSame($before,$quest->fresh()->getRawOriginal('payload'));$this->assertFalse($quest->fresh()->published);
    }
}
