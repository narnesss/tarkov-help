<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset,Quest};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,DB};
use Tests\TestCase;

/** Проверяет повторное использование публичного JSON и немедленную смену версии без личных данных. */
final class ContentCacheTest extends TestCase
{
    use RefreshDatabase;
    private function dataset(string $path,array $content): ContentDataset
    {
        $dataset=new ContentDataset(['path'=>$path]);$dataset->setContent($content);$dataset->save();return $dataset;
    }
    private function quest(string $mode='pve'): Quest
    {
        return Quest::create(['quest_id'=>'stable-quest','mode'=>$mode,'name'=>'Квест','trader_id'=>'prapor','published'=>true,
            'payload'=>['id'=>'stable-quest','name'=>'Квест','trader'=>['id'=>'prapor','name'=>'Прапор'],
                'objectives'=>[['id'=>'stable-goal','description'=>'Найти предмет']]]]);
    }
    public function test_warm_catalog_is_reused_and_edit_unpublish_and_republish_are_visible_immediately(): void
    {
        $this->dataset('guide/quests-pve.json',['traders'=>[]]);$quest=$this->quest();$url='/api/content/data/guide/quests-pve.json';
        $first=$this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertHeader('Cache-Control','no-store, private')->assertJsonPath('tasks.0.id','stable-quest');
        $this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','HIT')->assertContent($first->getContent());
        $payload=$quest->payload;$payload['name']='Исправлено';$quest->payload=$payload;$quest->name='Исправлено';$quest->save();
        $this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertJsonPath('tasks.0.name','Исправлено')->assertJsonPath('tasks.0.objectives.0.id','stable-goal');
        $quest->published=false;$quest->save();$this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertJsonCount(0,'tasks');
        $quest->published=true;$quest->save();$this->getJson($url)->assertOk()->assertJsonCount(1,'tasks')->assertJsonPath('tasks.0.id','stable-quest');
        $slots=array_filter(Cache::store()->getStore()->all(),fn($key)=>str_starts_with($key,'public-content-json-v1-'),ARRAY_FILTER_USE_KEY);
        $this->assertCount(1,$slots);
        DB::table('quests')->where('id',$quest->id)->delete();$this->getJson($url)->assertOk()->assertJsonCount(0,'tasks');
    }
    public function test_modes_dataset_versions_and_home_have_independent_cache_keys(): void
    {
        $this->dataset('guide/quests-pve.json',['traders'=>[]]);$this->dataset('guide/quests-regular.json',['traders'=>[]]);
        $home=$this->dataset('home.json',['modes'=>[]]);$this->quest('pve');$other=$this->quest('regular');
        $this->getJson('/api/content/data/home.json')->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertJsonPath('modes.pve.total',1)->assertJsonPath('modes.regular.total',1);
        $this->getJson('/api/content/data/home.json')->assertOk()->assertHeader('X-Wiki-Content-Cache','HIT');
        $this->getJson('/api/content/data/guide/quests-pve.json')->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertJsonCount(1,'tasks');
        $other->published=false;$other->save();
        $this->getJson('/api/content/data/guide/quests-pve.json')->assertOk()->assertHeader('X-Wiki-Content-Cache','HIT');
        $this->getJson('/api/content/data/guide/quests-regular.json')->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertJsonCount(0,'tasks');
        $this->getJson('/api/content/data/home.json')->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertJsonPath('modes.regular.total',0);
        $home->setContent(['modes'=>[],'title'=>'Изменённая главная']);$home->save();
        $this->getJson('/api/content/data/home.json')->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertJsonPath('title','Изменённая главная');
    }
    public function test_dataset_cache_does_not_bypass_proxy_guard_or_hide_edits_and_removal(): void
    {
        $dataset=$this->dataset('maps.json',[['id'=>'map','name'=>'Маяк']]);$url='/api/content/data/maps.json';
        $this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS');
        config(['api.proxy_required'=>true,'api.proxy_token'=>str_repeat('s',64)]);
        $this->getJson($url)->assertForbidden();$headers=['X-Wiki-Proxy-Token'=>str_repeat('s',64),'X-Wiki-Client-IP'=>'198.51.100.1'];
        $this->getJson($url,$headers)->assertOk()->assertHeader('X-Wiki-Content-Cache','HIT');
        $dataset->setContent([['id'=>'map','name'=>'Исправленная локация']]);$dataset->save();
        $this->getJson($url,$headers)->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertJsonPath('0.name','Исправленная локация');
        DB::table('content_datasets')->where('id',$dataset->id)->delete();$this->getJson($url,$headers)->assertNotFound();
    }
    public function test_reference_and_item_catalogs_reuse_public_cache_without_personal_progress(): void
    {
        $reference=$this->dataset('guide/reference-pve.json',['achievements'=>[['id'=>'achievement','name'=>'Достижение']],'crafts'=>[]]);
        $this->dataset('guide/items-pve.json',['items'=>[['id'=>'watch','name'=>'Часы']]]);
        foreach(['reference','items'] as $name){$url='/api/content/data/guide/'.$name.'-pve.json';
            $this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertHeader('Cache-Control','no-store, private');
            $this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','HIT')->assertHeaderMissing('Set-Cookie')->assertDontSee('user_state',false);
        }
        $reference->setContent(['achievements'=>[['id'=>'achievement','name'=>'Обновлённое достижение']],'crafts'=>[]]);$reference->save();
        $this->getJson('/api/content/data/guide/reference-pve.json')->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertJsonPath('achievements.0.name','Обновлённое достижение');
        $this->getJson('/api/content/data/guide/items-pve.json')->assertOk()->assertHeader('X-Wiki-Content-Cache','HIT')->assertJsonPath('items.0.name','Часы');
    }
    public function test_unavailable_cache_does_not_break_public_content(): void
    {
        $this->dataset('maps.json',[['id'=>'map','name'=>'Маяк']]);
        Cache::shouldReceive('get')->once()->andThrow(new \RuntimeException('test-cache-read-failure'));
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('test-cache-write-failure'));
        $this->getJson('/api/content/data/maps.json')->assertOk()->assertHeader('X-Wiki-Content-Cache','BYPASS')->assertJsonPath('0.name','Маяк')->assertHeader('Cache-Control','no-store, private');
    }
}
