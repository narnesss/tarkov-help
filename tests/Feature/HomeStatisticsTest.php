<?php

declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset,Quest};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Счётчики главной следуют публикации квестов и версиям связанных файлов CMS. */
final class HomeStatisticsTest extends TestCase
{
    use RefreshDatabase;
    private function dataset(string $path,array $content): ContentDataset
    {
        return ContentDataset::withoutEvents(function()use($path,$content){
            $row=new ContentDataset(['path'=>$path]);$row->setContent($content);$row->save();return $row;
        });
    }
    public function test_counts_ignore_snapshots_archives_and_drafts_and_update_a_warm_cache(): void
    {
        $this->dataset('home.json',['modes'=>['pve'=>['total'=>999,'kappa'=>999]],'maps'=>[['id'=>'retired','name'=>'Прежняя карта']]]);
        $raid=$this->dataset('raid/maps.json',[['id'=>'lighthouse','name'=>'Маяк'],['id'=>'retired','name'=>'Архив','cmsArchived'=>true]]);
        $maps=$this->dataset('maps.json',[['id'=>'customs'],['id'=>'retired','cmsArchived'=>true]]);
        $points=$this->dataset('spawns.json',[['id'=>'one','map'=>'customs'],['id'=>'old','map'=>'customs','cmsArchived'=>true],['id'=>'hidden-map','map'=>'retired']]);
        $this->dataset('docs.json',[['id'=>'medical'],['id'=>'old','cmsArchived'=>true]]);
        $make=function(string $id,string $mode,bool $published,bool $kappa,bool $archive=false): Quest {
            return Quest::withoutEvents(fn()=>Quest::create(['quest_id'=>$id,'mode'=>$mode,'published'=>$published,'name'=>$id,'trader_id'=>'trader',
                'payload'=>['id'=>$id,'name'=>$id,'trader'=>['id'=>'trader','name'=>'Торговец'],'objectives'=>[],
                    'kappaRequired'=>$kappa,'catalogSection'=>$archive?'supplementary':'main']]));
        };
        $quest=$make('pve-task','pve',true,true);$make('draft','pve',false,true);$make('archive','pve',true,true,true);$make('pvp-task','regular',true,false);
        $url='/api/content/data/home.json';
        $this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')
            ->assertJsonPath('modes.pve.total',1)->assertJsonPath('modes.pve.kappa',1)->assertJsonPath('modes.regular.total',1)->assertJsonPath('modes.regular.kappa',0)
            ->assertJsonPath('statistics.maps',1)->assertJsonPath('statistics.documentMaps',1)->assertJsonPath('statistics.documentPoints',1)->assertJsonPath('statistics.documentTypes',1)
            ->assertJsonPath('maps.0.name','Маяк')->assertJsonCount(1,'maps');
        $warm=$this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','HIT');
        $revision=$this->getJson($url.'?revision=1')->json('revision');
        ContentDataset::withoutEvents(function()use($raid){$raid->setContent([['id'=>'lighthouse','name'=>'Маяк'],['id'=>'woods','name'=>'Лес']]);$raid->save();});
        $this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertJsonPath('statistics.maps',2)->assertJsonCount(2,'maps');
        $this->assertNotSame($revision,$this->getJson($url.'?revision=1')->json('revision'));
        ContentDataset::withoutEvents(function()use($points){$points->setContent([['id'=>'new','map'=>'customs'],['id'=>'second','map'=>'customs']]);$points->save();});
        $this->getJson($url)->assertOk()->assertJsonPath('statistics.documentPoints',2);
        Quest::withoutEvents(function()use($quest){$quest->published=false;$quest->save();});
        $this->getJson($url)->assertOk()->assertJsonPath('modes.pve.total',0)->assertJsonPath('modes.pve.kappa',0);
        ContentDataset::withoutEvents(fn()=>$maps->delete());
        $this->getJson($url)->assertOk()->assertJsonPath('statistics.documentMaps',null)->assertJsonPath('statistics.documentPoints',null);
    }
}
