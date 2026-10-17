<?php

declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset,Quest};
use App\Services\PublicMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Проверяет изображения в справочнике/наградах, автоматическую ревизию и сохранность исходных файлов. */
final class PublicMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_image_fields_are_replaced_in_all_json_encodings(): void
    {
        $source=array_key_first(config('public-media.aliases'));$asset=config('public-media.aliases')[$source];
        foreach([0,JSON_UNESCAPED_SLASHES,JSON_PRETTY_PRINT,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES] as $flags){
            $value=['id'=>'stable','name'=>'Посылка','iconLink'=>$source,'gridImageLink'=>$source,'originalUrl'=>$source,'sourceUrl'=>$source,
                'description'=>'Оригинал: '.$source,'other'=>['icon'=>$source],'custom'=>['imageLink'=>'https://editor.example/custom.webp']];
            $expected=$value;$expected['iconLink']=$asset;$expected['gridImageLink']=$asset;$expected['other']['icon']=$asset;
            $json=json_encode($value,$flags|JSON_THROW_ON_ERROR);
            $this->assertSame($expected,json_decode(PublicMedia::json($json),true,flags:JSON_THROW_ON_ERROR));
            $this->assertSame(PublicMedia::json($json),PublicMedia::json(PublicMedia::json($json)));
        }
        $this->assertFileExists(public_path(ltrim($asset,'/')));
    }

    public function test_catalog_details_and_quest_rewards_use_same_copy_without_data_writes(): void
    {
        $source=array_key_first(config('public-media.aliases'));$asset=config('public-media.aliases')[$source];
        $item=['id'=>'stable-item','name'=>'Посылка','iconLink'=>$source,'image512pxLink'=>$source,'originalUrl'=>$source];
        foreach(['guide/items-pve.json'=>['items'=>[$item]],'guide/quests-pve.json'=>['items'=>[$item]]] as $path=>$content){
            $row=new ContentDataset(['path'=>$path]);$row->setContent($content);ContentDataset::withoutEvents(fn()=>$row->save());
        }
        $payload=['id'=>'stable-quest','name'=>'Квест','objectives'=>[['id'=>'goal','item'=>'stable-item']],
            'startRewards'=>['items'=>[['item'=>$item,'count'=>2]]],'finishRewards'=>['items'=>[['item'=>$item,'count'=>3]]]];
        $quest=Quest::create(['quest_id'=>'stable-quest','mode'=>'pve','name'=>'Квест','published'=>true,'payload'=>$payload]);
        $refs=ContentDataset::orderBy('path')->pluck('payload')->all();
        $urls=['/api/content/data/guide/items-pve.json?view=catalog','/api/content/data/guide/items-pve.json?view=detail&id=stable-item'];
        foreach($urls as $url){$this->getJson($url)->assertOk()->assertHeaderMissing('Set-Cookie')->assertJsonPath('items.0.iconLink',$asset);}
        $url='/api/content/data/guide/quests-pve.json?view=detail&id=stable-quest';
        $response=$this->getJson($url)->assertOk()->assertJsonPath('items.0.iconLink',$asset)->assertJsonPath('items.0.originalUrl',$source)
            ->assertJsonPath('tasks.0.startRewards.items.0.item.iconLink',$asset)->assertJsonPath('tasks.0.startRewards.items.0.count',2)
            ->assertJsonPath('tasks.0.finishRewards.items.0.item.iconLink',$asset)->assertJsonPath('tasks.0.finishRewards.items.0.count',3);
        $this->getJson($url)->assertHeader('X-Wiki-Content-Cache','HIT');
        $this->getJson($url.'&revision=1')->assertJsonPath('revision',$response->headers->get('X-Wiki-Content-Revision'));
        $aliases=config('public-media.aliases');config(['public-media.aliases'=>[]]);
        $before=$this->getJson($url)->assertJsonPath('items.0.iconLink',$source)->headers->get('X-Wiki-Content-Revision');
        config(['public-media.aliases'=>$aliases]);
        $after=$this->getJson($url)->assertJsonPath('items.0.iconLink',$asset)->headers->get('X-Wiki-Content-Revision');
        $this->assertNotSame($before,$after);$this->assertSame($refs,ContentDataset::orderBy('path')->pluck('payload')->all());
        $this->assertSame($payload,$quest->fresh()->payload);$this->assertDatabaseCount('users',0);$this->assertDatabaseCount('user_state',0);
    }
}
