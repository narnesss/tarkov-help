<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Http\Controllers\ContentController;
use App\Models\ContentDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Проверяет объединённую загрузку публичных данных без изменений рабочей БД и прогресса. */
final class ContentBundleTest extends TestCase
{
    use RefreshDatabase;
    private function putDataset(string $path,array $value): ContentDataset
    {
        $dataset=ContentDataset::firstOrNew(['path'=>$path]);$dataset->setContent($value);
        ContentDataset::withoutEvents(fn()=>$dataset->save());return $dataset;
    }
    private function fixtures(string $name): void
    {
        foreach(ContentController::BUNDLES[$name] as $path=>$default){if($default===null){$this->putDataset($path,['source'=>$path,'name'=>'Публичные данные']);}}
    }
    public function test_bundle_is_cached_and_any_changed_file_is_visible_immediately(): void
    {
        $this->fixtures('quest-extras');$url='/api/content/bundle/quest-extras';
        $first=$this->getJson($url)->assertOk()->assertHeader('Cache-Control','no-store, private')->assertHeader('X-Wiki-Content-Cache','MISS');
        $this->assertCount(14,$first->json('files'));
        $this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','HIT')->assertContent($first->getContent());
        $this->putDataset('guide/story.json',['name'=>'Исправленная глава']);
        $next=$this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS');
        $this->assertSame('Исправленная глава',$next->json('files')['guide/story.json']['name']);
        $this->assertSame(['quests'=>[]],$next->json('files')['guide/quest-conditions.json']);
        $this->putDataset('guide/quest-conditions.json',['quests'=>['stable'=>['conditions'=>[]]]]);
        $added=$this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS');
        $this->assertArrayHasKey('stable',$added->json('files')['guide/quest-conditions.json']['quests']);
        DB::table('content_datasets')->where('path','guide/story.json')->delete();$this->getJson($url)->assertNotFound();
        $this->assertSame(0,DB::table('users')->count());$this->assertSame(0,DB::table('user_state')->count());
    }
    public function test_map_bundle_preserves_archiving_and_requires_proxy_even_when_warm(): void
    {
        $this->fixtures('raid-base');$this->putDataset('raid/loose-items.json',['items'=>[['id'=>'visible'],['id'=>'hidden','cmsArchived'=>true]]]);
        $url='/api/content/bundle/raid-base';$reply=$this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','MISS')->assertHeaderMissing('Set-Cookie');
        $this->assertSame([['id'=>'visible']],$reply->json('files')['raid/loose-items.json']['items']);
        config(['api.proxy_required'=>true,'api.proxy_token'=>str_repeat('s',64)]);
        $this->getJson($url)->assertForbidden();
        $this->getJson($url,['X-Wiki-Proxy-Token'=>str_repeat('s',64),'X-Wiki-Client-IP'=>'198.51.100.1'])->assertOk()->assertHeader('X-Wiki-Content-Cache','HIT');
        $this->getJson('/api/content/bundle/users')->assertNotFound();
    }
    public function test_card_support_excludes_bulk_media_and_has_small_revision_check(): void
    {
        $this->fixtures('quest-ui');$url='/api/content/bundle/quest-ui';
        $data=$this->getJson($url)->assertOk()->assertHeaderMissing('Set-Cookie');$this->assertCount(5,$data->json('files'));
        $this->assertArrayNotHasKey('guide/quest-media.json',$data->json('files'));
        $revision=$this->getJson($url.'?revision=1')->assertOk()->assertHeader('Cache-Control','no-store, private');
        $this->assertSame($data->headers->get('X-Wiki-Content-Revision'),$revision->json('revision'));$this->assertLessThan(100,strlen($revision->getContent()));
    }
}
