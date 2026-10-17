<?php

declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset,Quest};
use App\Services\{ContentUpdates,WikiImporter,WikiSync};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Проверяет настоящий пакет шести квестов на копии прежних справочников. */
final class HiddenRouteEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_package_links_actual_items_rewards_and_prerequisites_without_changing_progress(): void
    {
        $patch=json_decode(file_get_contents(public_path('data/guide/quest-patch-1150.json')),true,flags:JSON_THROW_ON_ERROR);
        $prefix='event-20261016-';$known=[];
        foreach($patch['modes']['pve']['tasks'] as $task){if(!str_starts_with($task['id'],$prefix)){continue;}
            foreach(['startRewards','finishRewards'] as $timing){foreach($task[$timing]['items'] as $reward){$known[$reward['item']]=true;}}
        }
        app(WikiSync::class)->run(function()use($patch,$prefix,$known): void {
            foreach(['pve','regular'] as $mode){
                $bundle=json_decode(gzdecode(file_get_contents(public_path('data/guide/quests-'.$mode.'.json.gz'))),true,flags:JSON_THROW_ON_ERROR);
                $items=array_filter($bundle['items'],fn($item)=>isset($known[$item['id']]));
                $catalog=['items'=>$items,'questItems'=>[],'traders'=>array_values(array_filter($bundle['traders'],fn($t)=>in_array($t['id'],['54cb57776803fa99248b456e','5a7c2eca46aef81a7ca2145d'],true))),
                    'maps'=>$bundle['maps'],'skills'=>$bundle['skills']];
                foreach(['guide/quests-'.$mode.'.json'=>$catalog,'guide/items-'.$mode.'.json'=>['items'=>array_values($items)]] as $path=>$value){
                    $dataset=new ContentDataset(['path'=>$path]);$dataset->setContent($value);$dataset->save();
                }
                foreach($patch['modes'][$mode]['tasks'] as $task){if(!str_starts_with($task['id'],$prefix)){continue;}
                    $trader=collect($bundle['traders'])->firstWhere('id',$task['trader']);$task['trader']=$trader;
                    Quest::create(['quest_id'=>$task['id'],'mode'=>$mode,'name'=>$task['name'],'trader_id'=>$trader['id'],'published'=>true,'payload'=>$task]);
                }
            }
        });
        app(WikiImporter::class)->rebuild();
        DB::table('users')->insert(['id'=>'event-test-player','username'=>'Игрок для проверки','password_hash'=>'unchanged','created_at'=>time()]);
        DB::table('user_state')->insert(['user_id'=>'event-test-player','state_key'=>'saved-progress','value'=>'{"quest":"old-id","goal":"old-goal"}','version'=>7,'updated_at'=>time()]);
        $state=DB::table('user_state')->first();$payloads=Quest::orderBy('id')->get()->map(fn($q)=>$q->getRawOriginal('payload'))->all();
        $update=json_decode(file_get_contents(database_path('content-updates/20261017-hidden-route-event.json')),true,flags:JSON_THROW_ON_ERROR);
        $this->assertFalse(app(ContentUpdates::class)->apply($update,true)['applied']);
        $this->assertDatabaseMissing('wiki_items',['external_id'=>$prefix.'shipment-ssd']);
        $result=app(ContentUpdates::class)->apply($update);$this->assertSame(12,$result['quests']);$this->assertSame(4,$result['datasets']);
        $this->assertDatabaseCount('quests',12);$this->assertDatabaseCount('wiki_objectives',24);
        foreach(['scourge-smartphone','precursor-container','employee-suitcase','employee-package','shipment-ssd'] as $slug){
            $item=DB::table('wiki_items')->where('external_id',$prefix.$slug)->value('id');$this->assertNotNull($item,$slug);
            $this->assertTrue(DB::table('wiki_objective_items')->where('item_id',$item)->where('archived',0)->exists(),$slug);
        }
        foreach(['pve','regular'] as $mode){
            $doxed=DB::table('wiki_quests')->where(['external_id'=>$prefix.'doxed','mode'=>$mode])->value('id');
            $side=DB::table('wiki_quests')->where(['external_id'=>$prefix.'side-hustle','mode'=>$mode])->value('id');
            $groups=DB::table('wiki_prerequisite_groups')->where('quest_id',$doxed)->pluck('id');
            $this->assertTrue(DB::table('wiki_quest_prerequisites')->whereIn('group_id',$groups)->where(['prerequisite_quest_id'=>$side,'status'=>'active','archived'=>0])->exists());
        }
        $this->assertEquals($state,DB::table('user_state')->first());
        $this->assertSame($payloads,Quest::orderBy('id')->get()->map(fn($q)=>$q->getRawOriginal('payload'))->all());
        foreach(ContentDataset::all() as $dataset){$this->assertStringStartsWith('@file:',$dataset->getRawOriginal('payload'));}
        $this->assertFalse(app(ContentUpdates::class)->apply($update)['applied']);
        $this->assertDatabaseCount('quests',12);
    }
}
