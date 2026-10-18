<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset,Quest};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Проверяет отложенные карточки, совместимость старого API и целостность прогресса. */
final class ContentViewsTest extends TestCase
{
    use RefreshDatabase;
    private function dataset(string $path,array $content): void
    { $row=new ContentDataset(['path'=>$path]);$row->setContent($content);ContentDataset::withoutEvents(fn()=>$row->save()); }
    private function fixtures(): Quest
    {
        $this->dataset('guide/quests-pve.json',['meta'=>['mode'=>'pve'],'items'=>[['id'=>'used','name'=>'Нужный'],['id'=>'unrelated','name'=>'Другой']],
            'questItems'=>[['id'=>'stable-item','name'=>'Квестовый']],'maps'=>[['id'=>'map','normalizedName'=>'woods']],
            'traders'=>[['id'=>'prapor','name'=>'Прапор','tasks'=>array_fill(0,200,'лишнее')]]]);
        return Quest::create(['quest_id'=>'stable','mode'=>'pve','name'=>'Задание','published'=>true,'trader_id'=>'prapor','payload'=>[
            'id'=>'stable','name'=>'Задание','trader'=>['id'=>'prapor','name'=>'Прапор','tasks'=>array_fill(0,200,'лишнее')],
            'objectives'=>[['id'=>'goal','description'=>'Найти предмет','type'=>'giveItem','item'=>['id'=>'used','name'=>'Нужный'], 'media'=>[['url'=>'https://example.test/image.jpg']]],['id'=>'archived','cmsArchived'=>true]],
            'storyBranches'=>[['id'=>'branch','objectiveIds'=>['goal']]],'taskRequirements'=>[['task'=>['id'=>'previous'],'status'=>['complete']]],
            'finishRewards'=>['items'=>[['item'=>['id'=>'used'],'count'=>4]]],'questContent'=>['briefing'=>['paragraphs'=>[str_repeat('Текст ',5000)]]],
        ]]);
    }
    public function test_catalog_keeps_ids_conditions_search_and_rewards_but_defers_heavy_card(): void
    {
        $this->fixtures();$url='/api/content/data/guide/quests-pve.json';
        $full=$this->getJson($url)->assertOk();$small=$this->getJson($url.'?view=catalog')->assertOk()->assertHeader('Cache-Control','no-store, private')->assertHeaderMissing('Set-Cookie');
        $small->assertJsonPath('catalog',true)->assertJsonPath('tasks.0.id','stable')->assertJsonPath('tasks.0.objectives.0.id','goal')
            ->assertJsonPath('tasks.0.objectives.0.description','Найти предмет')->assertJsonCount(1,'tasks.0.objectives')->assertJsonPath('tasks.0._detailPending',true)
            ->assertJsonPath('tasks.0.taskRequirements.0.task.id','previous')->assertJsonPath('tasks.0.finishRewards.items.0.count',4)->assertJsonPath('tasks.0.storyBranches.0.objectiveIds.0','goal');
        $this->assertArrayNotHasKey('questContent',$small->json('tasks.0'));$this->assertArrayNotHasKey('tasks',$small->json('tasks.0.trader'));
        $this->assertLessThan(strlen($full->getContent())/5,strlen($small->getContent()));
        $this->getJson($url.'?view=catalog')->assertOk()->assertHeader('X-Wiki-Content-Cache','HIT');
        $detail=$this->getJson($url.'?view=detail&id=stable')->assertOk()->assertJsonCount(1,'tasks')->assertJsonCount(1,'items')->assertJsonPath('items.0.id','used')->assertJsonPath('tasks.0.objectives.0.media.0.url','https://example.test/image.jpg');
        $this->assertSame($full->json('tasks.0'),$detail->json('tasks.0'));$this->assertDatabaseCount('users',0);$this->assertDatabaseCount('user_state',0);
    }
    public function test_conditional_public_read_survives_ten_minutes_and_rechecks_edits_and_unpublishing(): void
    {
        $quest=$this->fixtures();$url='/api/content/data/guide/quests-pve.json?view=detail&id=stable';
        $first=$this->getJson($url)->assertOk();$revision=$first->headers->get('X-Wiki-Content-Revision');
        $this->travel(11)->minutes();
        $this->getJson($url)->assertOk()->assertHeader('X-Wiki-Content-Cache','HIT');
        $this->getJson($url,['X-Wiki-Content-Revision'=>$revision])->assertOk()->assertHeader('X-Wiki-Content-Unchanged','1')->assertHeaderMissing('Set-Cookie')->assertExactJson(['revision'=>$revision]);
        $payload=$quest->payload;$payload['name']='Изменено';$quest->payload=$payload;$quest->save();
        $this->getJson($url,['X-Wiki-Content-Revision'=>$revision])->assertOk()->assertHeaderMissing('X-Wiki-Content-Unchanged')->assertJsonPath('tasks.0.name','Изменено');
        $quest->published=false;$quest->save();$this->getJson($url,['X-Wiki-Content-Revision'=>$revision])->assertNotFound();
        $this->assertDatabaseCount('users',0);$this->assertDatabaseCount('user_state',0);
    }

    public function test_edits_modes_unpublishing_and_wrong_query_cannot_return_stale_details(): void
    {
        $quest=$this->fixtures();$url='/api/content/data/guide/quests-pve.json';
        $this->getJson($url.'?view=detail&id=stable')->assertOk();$quest->published=false;$quest->save();
        $this->getJson($url.'?view=detail&id=stable')->assertNotFound();$this->getJson($url.'?view=catalog')->assertJsonCount(0,'tasks');
        foreach(['view[]=catalog','view=unsupported','view=detail','view=detail&id[]=stable','view=catalog&id=stable','view=detail&id=%27%20OR%201%3D1'] as $query){$this->getJson($url.'?'.$query)->assertUnprocessable();}
        $this->getJson($url.'?view=detail&id=other')->assertNotFound();
        $quest->published=true;$payload=$quest->payload;$payload['name']='Исправлено';$quest->payload=$payload;$quest->save();
        $this->getJson($url.'?view=detail&id=stable')->assertJsonPath('tasks.0.name','Исправлено');
    }
    public function test_small_revision_check_changes_after_edit_and_keeps_views_separate(): void
    {
        $quest=$this->fixtures();$url='/api/content/data/guide/quests-pve.json?view=catalog';
        $revision=$this->getJson($url.'&revision=1')->assertOk()->assertHeader('Cache-Control','no-store, private')->assertHeaderMissing('Set-Cookie');
        $this->assertLessThan(100,strlen($revision->getContent()));$this->assertSame($revision->json('revision'),$this->getJson($url)->headers->get('X-Wiki-Content-Revision'));
        $this->assertNotSame($revision->json('revision'),$this->getJson('/api/content/data/guide/quests-pve.json?revision=1')->json('revision'));
        $payload=$quest->payload;$payload['name']='Новая редакция';$quest->payload=$payload;$quest->save();
        $this->assertNotSame($revision->json('revision'),$this->getJson($url.'&revision=1')->json('revision'));
        $this->getJson('/api/content/data/guide/quests-pve.json?view=lookup')->assertOk()->assertJsonPath('lookup',true)->assertJsonCount(0,'tasks')->assertJsonPath('questItems.0.id','stable-item');
        $this->getJson($url.'&revision[]=1')->assertUnprocessable();$this->assertDatabaseCount('user_state',0);
    }
    public function test_item_cards_and_reference_views_preserve_source_and_hide_unrelated_metadata(): void
    {
        $this->dataset('guide/items-pve.json',['meta'=>[],'items'=>[['id'=>'item','name'=>'Предмет','description'=>'Поисковый текст','image512pxLink'=>'https://example.test/image.jpg','sellFor'=>[['priceRUB'=>123]],'properties'=>['propertiesType'=>'ItemPropertiesAmmo','damage'=>70]],['id'=>'archived','cmsArchived'=>true]]]);
        $this->getJson('/api/content/data/guide/items-pve.json?view=catalog')->assertOk()->assertJsonPath('catalog',true)->assertJsonCount(1,'items')->assertJsonPath('items.0.marketTraderPrice',123)->assertJsonPath('items.0.properties.damage',70)->assertJsonMissingPath('items.0.image512pxLink');
        $this->getJson('/api/content/data/guide/items-pve.json?view=detail&id=item')->assertOk()->assertJsonPath('items.0.image512pxLink','https://example.test/image.jpg');
        $this->getJson('/api/content/data/guide/items-pve.json?view=detail&id=archived')->assertNotFound();
        $this->dataset('guide/reference-pve.json',['maps'=>[['id'=>'woods','name'=>'Лес','bosses'=>[['mob'=>'boss']], 'pois'=>array_fill(0,100,'точка')]],'mapMetadata'=>['large'=>'метаданные'],'crafts'=>[['id'=>'craft']]]);
        $this->getJson('/api/content/data/guide/reference-pve.json?view=catalog')->assertOk()->assertJsonPath('maps.0.bosses.0.mob','boss')->assertJsonPath('crafts.0.id','craft')->assertJsonMissingPath('mapMetadata')->assertJsonMissingPath('maps.0.pois');
        $this->getJson('/api/content/data/guide/reference-pve.json')->assertJsonPath('mapMetadata.large','метаданные');
    }
    public function test_boss_detail_contains_selected_profile_related_links_and_only_its_spawns(): void
    {
        $this->dataset('guide/bosses.json',['profiles'=>[
            ['id'=>'followerBirdEye','slug'=>'birdeye','name'=>'Зрячий','spawnParentMobId'=>'bossKnight','canonicalMapSlugs'=>['lighthouse'],'description'=>'Описание'],
            ['id'=>'bossKnight','slug'=>'knight','name'=>'Рыцарь','description'=>'Не нужно в карточке Зрячего'],
            ['id'=>'bossKilla','slug'=>'killa','name'=>'Килла'],
        ]]);
        $this->dataset('guide/reference-pve.json',['meta'=>['fetchedAt'=>'2026-10-17'],'achievements'=>[['id'=>'unrelated']],'maps'=>[
            ['id'=>'lighthouse','normalizedName'=>'lighthouse','name'=>'Маяк','pois'=>['unrelated'],'bosses'=>[['mob'=>'bossKnight','spawnChance'=>0.4],['mob'=>'bossKilla']]],
            ['id'=>'interchange','normalizedName'=>'interchange','name'=>'Развязка','bosses'=>[['mob'=>'bossKilla']]],
        ]]);
        $url='/api/content/data/guide/bosses.json?view=detail&id=birdeye&mode=pve';
        $this->getJson($url)->assertOk()->assertJsonCount(2,'profiles')->assertJsonCount(1,'reference.maps')->assertJsonCount(1,'reference.maps.0.bosses')
            ->assertJsonPath('profiles.0.name','Зрячий')->assertJsonPath('reference.maps.0.bosses.0.mob','bossKnight')->assertJsonMissingPath('profiles.1.description')->assertJsonMissingPath('reference.maps.0.pois')->assertJsonMissingPath('reference.achievements');
        $this->getJson($url)->assertHeader('X-Wiki-Content-Cache','HIT');
        $this->getJson('/api/content/data/guide/bosses.json?view=detail&id=missing&mode=pve')->assertNotFound();
        $this->getJson($url.'&mode[]=pve')->assertUnprocessable();$this->assertDatabaseCount('user_state',0);
    }

    public function test_detail_has_only_related_quest_context_and_media_is_localized_by_backend(): void
    {
        $this->fixtures();
        Quest::create(['quest_id'=>'previous','mode'=>'pve','name'=>'До','trader_id'=>'prapor','published'=>true,'payload'=>['id'=>'previous','name'=>'До','objectives'=>[['id'=>'previous-goal']]]]);
        Quest::create(['quest_id'=>'unrelated','mode'=>'pve','name'=>'Другой','trader_id'=>'prapor','published'=>true,'payload'=>['id'=>'unrelated','name'=>'Другой','objectives'=>[]]]);
        $this->dataset('guide/media-localization.json',['images'=>['https://example.test/image.jpg'=>['url'=>'assets/ru.webp']]]);
        $this->getJson('/api/content/data/guide/quests-pve.json?view=detail&id=stable')->assertOk()->assertJsonPath('partial',true)->assertJsonCount(1,'tasks')->assertJsonCount(1,'contextTasks')
            ->assertJsonPath('contextTasks.0.id','previous')->assertJsonPath('contextTasks.0.objectives.0.id','previous-goal')->assertJsonPath('tasks.0.objectives.0.media.0.url','/assets/ru.webp')->assertJsonPath('ui.uiReady',true);
        $this->assertDatabaseCount('users',0);$this->assertDatabaseCount('user_state',0);
    }

    public function test_reference_sections_exclude_other_datasets_and_item_cards_do_not_include_catalogs(): void
    {
        $this->dataset('guide/reference-pve.json',['meta'=>[],'achievements'=>[['id'=>'achievement']],'crafts'=>[['id'=>'craft','requiredItems'=>[['item'=>['id'=>'item']]]],['id'=>'other','requiredItems'=>[['item'=>['id'=>'other']]]]],'maps'=>[['id'=>'woods']]]);
        $this->dataset('guide/items-pve.json',['meta'=>[],'items'=>[['id'=>'item','name'=>'Предмет'],['id'=>'other','name'=>'Другой']]]);
        $this->getJson('/api/content/data/guide/reference-pve.json?view=catalog&section=achievements')->assertOk()->assertJsonCount(1,'achievements')->assertJsonMissingPath('crafts')->assertJsonMissingPath('maps');
        $this->getJson('/api/content/data/guide/items-pve.json?view=detail&id=item')->assertOk()->assertJsonCount(1,'items')->assertJsonCount(1,'related.reference.crafts')->assertJsonPath('related.reference.crafts.0.id','craft')->assertJsonMissingPath('related.reference.maps');
        $this->getJson('/api/content/data/guide/reference-pve.json?view=catalog&section=unknown')->assertUnprocessable();
    }

    public function test_php_applies_file_media_defaults_without_overriding_editor_choice(): void
    {
        $quest=$this->fixtures();$images=json_decode(gzdecode(file_get_contents(public_path('data/guide/media-localization.json.gz'))),true)['images'];
        $source=array_key_first($images);$payload=$quest->payload;$payload['objectives'][0]['media']=[['url'=>$source,'id'=>'stable-frame','objectiveIds'=>['goal']]];$quest->payload=$payload;$quest->save();
        $url='/api/content/data/guide/quests-pve.json?view=detail&id=stable';
        $this->getJson($url)->assertOk()->assertJsonPath('tasks.0.objectives.0.media.0.url','/'.ltrim($images[$source]['url'],'/'))->assertJsonPath('tasks.0.objectives.0.media.0.id','stable-frame');
        $this->dataset('guide/media-localization.json',['images'=>[$source=>['url'=>'assets/editor.webp']]]);
        $this->getJson($url)->assertJsonPath('tasks.0.objectives.0.media.0.url','/assets/editor.webp');
    }

    public function test_media_paths_work_on_nested_pages_without_rewriting_source_records(): void
    {
        $quest=$this->fixtures();$payload=$quest->payload;
        $frames=[['id'=>'stable-photo','url'=>'assets/photo.webp','fullUrl'=>'./assets/full.webp','objectiveIds'=>['goal'],'association'=>'objective'],
            ['url'=>'https://editor.example/photo.webp?version=2','fullUrl'=>'https://editor.example/full.webp'],['url'=>'/assets/already-root.webp']];
        $payload['gallery']=$frames;$payload['media']['gallery']=$frames;$payload['questContent']['gallery']=$frames;$payload['objectives'][0]['media']=$frames;
        $quest->payload=$payload;$quest->save();
        $response=$this->getJson('/api/content/data/guide/quests-pve.json?view=detail&id=stable')->assertOk();
        foreach(['gallery','media.gallery','questContent.gallery','objectives.0.media'] as $key){
            $response->assertJsonPath('tasks.0.'.$key.'.0.url','/assets/photo.webp')->assertJsonPath('tasks.0.'.$key.'.0.fullUrl','/assets/full.webp')
                ->assertJsonPath('tasks.0.'.$key.'.0.id','stable-photo')->assertJsonPath('tasks.0.'.$key.'.0.objectiveIds',['goal'])
                ->assertJsonPath('tasks.0.'.$key.'.0.association','objective')->assertJsonPath('tasks.0.'.$key.'.1',$frames[1])->assertJsonPath('tasks.0.'.$key.'.2',$frames[2]);
        }
        $this->assertSame($payload,$quest->fresh()->payload);$this->assertDatabaseCount('users',0);$this->assertDatabaseCount('user_state',0);
    }

    public function test_item_search_names_are_read_from_backend_and_map_frame_loads_one_quest_only(): void
    {
        $quest=$this->fixtures();$this->dataset('guide/items-pve.json',['items'=>[['id'=>'active','name'=>'Новое имя','description'=>'Лишний текст'],['id'=>'archived','cmsArchived'=>true]]]);
        $this->getJson('/api/content/data/guide/items-pve.json?view=lookup')->assertOk()->assertJsonPath('lookup',true)->assertJsonCount(1,'items')->assertJsonPath('items.0.name','Новое имя')->assertJsonMissingPath('items.0.description');
        $this->dataset('guide/quest-map-index.json',['sourceMaps'=>['woods'=>['width'=>100], 'other'=>['width'=>200]],'sourceQuests'=>['stable'=>['woods'=>[['id'=>'goal']],'other'=>[['id'=>'unrelated']]],'unrelated'=>['woods'=>[['id'=>'other']]]]]);
        $url='/api/content/data/guide/quest-map-index.json?view=detail&id=stable&map=woods&mode=pve';
        $this->getJson($url)->assertOk()->assertJsonPath('sourceQuests.stable.woods.0.id','goal')->assertJsonMissingPath('sourceMaps.other')->assertJsonMissingPath('sourceQuests.unrelated')->assertJsonMissingPath('sourceQuests.stable.other');
        $this->getJson($url.'&map[]=woods')->assertUnprocessable();
        $quest->published=false;$quest->save();$this->getJson($url)->assertNotFound();$this->assertDatabaseCount('user_state',0);
    }

}
