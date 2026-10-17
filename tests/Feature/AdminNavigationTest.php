<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset,Quest,User};
use App\Services\{DatasetCatalog,ItemCatalog,WikiImporter,WikiStore};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Вкладки и фильтры читают данные, не меняя режимы, ID, ссылки и прогресс. */
final class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;
    private function fixtures(): void
    {
        foreach(['maps.json'=>[['id'=>'lighthouse','name'=>'Маяк','file'=>'/map.svg','width'=>1000,'height'=>800]],
            'docs.json'=>[['id'=>'doc','name'=>'Письмо']],
            'guide/items-pve.json'=>['items'=>[
                ['id'=>'real-key','name'=>'Ключ от двери','types'=>['keys']],
                ['id'=>'wrench','name'=>'Гаечный ключ','types'=>['barter']],
                ['id'=>'torch','name'=>'Газовый резак','types'=>['keys']],
                ['id'=>'case','name'=>'Кейс для ключей','types'=>['container']],
                ['id'=>'ratchet','name'=>'Трещоточный ключ','normalizedName'=>'ratchet-wrench','types'=>['barter']],
                ['id'=>'pipe-grip','name'=>'Шарнирный ключ КШС','normalizedName'=>'pipe-grip-wrench','types'=>['barter']],
            ]],
            'guide/quests-pve.json'=>['traders'=>[['id'=>'prapor','name'=>'Прапор']]],
            'guide/bosses.json'=>['bosses'=>[]],
            'guide/reference-pve.json'=>['achievements'=>[],'crafts'=>[],'hideout'=>[],'barters'=>[],'traders'=>[],'maps'=>[]],
            'spawns.json'=>[['id'=>'point','map'=>'lighthouse','doc'=>'doc','caption'=>'Документ у дерева','x'=>10,'y'=>20,'keyIds'=>['real-key','wrench','torch','case','ratchet','pipe-grip']]],
        ] as $path=>$data){$dataset=new ContentDataset(['path'=>$path]);$dataset->setContent($data);$dataset->save();}
        foreach(['pve','regular'] as $mode){foreach(['ordinary','story','kappa'] as $kind){$name=$mode.' '.$kind;
            Quest::create(['quest_id'=>$kind,'mode'=>$mode,'name'=>$name,'trader_id'=>'prapor','published'=>$kind!=='story',
                'payload'=>['id'=>$kind,'name'=>$name,'trader'=>['id'=>'prapor','name'=>'Прапор'],'story'=>$kind==='story','kappaRequired'=>$kind==='kappa','objectives'=>[]]]);
        }}
        app(WikiImporter::class)->rebuild();$location=DB::table('wiki_locations')->value('id');
        foreach(['pve','regular'] as $mode){app(WikiStore::class)->put('map_points',['external_id'=>'raid-'.$mode,'mode'=>$mode],['location_id'=>$location,'title'=>'Интерактивная '.$mode,'source_kind'=>'raid']);}
        DB::table('users')->insert(['id'=>str_repeat('a',32),'username'=>'existing','created_at'=>time()]);
        DB::table('user_state')->insert(['user_id'=>str_repeat('a',32),'state_key'=>'progress','value'=>'{"done":["ordinary"]}','version'=>7,'updated_at'=>time()]);
        $this->actingAs(User::create(['name'=>'Редактор','email'=>'navigation@example.test','password'=>bcrypt('test'),'permissions'=>['platform.index'=>true,'wiki.content'=>true]]));
    }
    private function snapshot(): string
    {
        $rows=[];foreach(DB::getSchemaBuilder()->getTableListing(schemaQualified:false) as $table){if(str_starts_with($table,'wiki_')||in_array($table,['quests','content_datasets','users','user_state'],true)){$rows[$table]=DB::table($table)->get()->all();}}
        return hash('sha256',serialize($rows));
    }
    public function test_quest_modes_categories_and_filters_are_server_side_and_preserve_data(): void
    {
        $this->fixtures();$before=$this->snapshot();
        foreach(['pve','regular'] as $mode){
            $this->get('/admin/quests?mode='.$mode)->assertOk()->assertSee($mode.' ordinary')->assertDontSee(($mode==='pve'?'regular':'pve').' ordinary')->assertSee('aria-current="page"',false);
            $this->get('/admin/quests?mode='.$mode.'&section=story')->assertOk()->assertSee($mode.' story')->assertDontSee($mode.' ordinary');
            $this->get('/admin/quests?mode='.$mode.'&section=kappa')->assertOk()->assertSee($mode.' kappa')->assertDontSee($mode.' ordinary');
            $this->get('/admin/quests?mode='.$mode.'&state=draft')->assertOk()->assertSee($mode.' story')->assertDontSee($mode.' ordinary');
        }
        $this->post('/admin/quests/filter',['mode'=>'regular','section'=>'story','state'=>'draft','trader'=>'prapor','search'=>'  story  '])->assertRedirect(route('platform.quests',['mode'=>'regular','section'=>'story','state'=>'draft','trader'=>'prapor','search'=>'story']));
        $html=$this->get('/admin/quests/create?mode=regular')->assertOk()->getContent();$dom=new \DOMDocument();@$dom->loadHTML($html);$xpath=new \DOMXPath($dom);
        $this->assertSame(1,$xpath->query('//select[@name="quest[mode]"]/option[@value="regular" and @selected]')->length);
        $this->assertSame($before,$this->snapshot());
    }
    public function test_tools_and_containers_do_not_appear_in_keys_but_old_links_remain_accessible(): void
    {
        $this->fixtures();$before=$this->snapshot();
        $this->get('/admin/wiki/keys')->assertOk()->assertSee('Ключ от двери')->assertDontSee('Гаечный ключ')->assertDontSee('Газовый резак')->assertDontSee('Кейс для ключей')->assertDontSee('Трещоточный ключ')->assertDontSee('Шарнирный ключ КШС');
        $this->get('/admin/wiki/keys?item_group=related')->assertOk()->assertSee('Гаечный ключ')->assertSee('Газовый резак')->assertSee('Кейс для ключей')->assertSee('Трещоточный ключ')->assertSee('Шарнирный ключ КШС');
        foreach(['tools'=>['Гаечный ключ','Газовый резак','Трещоточный ключ','Шарнирный ключ КШС'],'containers'=>['Кейс для ключей'],'keys'=>['Ключ от двери']] as $group=>$names){
            $response=$this->get('/admin/wiki/items?item_group='.$group)->assertOk();foreach($names as $name){$response->assertSee($name);}
        }
        foreach(array_keys(ItemCatalog::GROUPS) as $group){$this->get('/admin/wiki/items?item_group='.$group)->assertOk();}
        $this->assertSame($before,$this->snapshot());
    }
    public function test_wrenches_are_tools_with_or_without_localized_names_and_hinge_mod_is_not_a_tool(): void
    {
        foreach(['Шарнирный ключ КШС','Трещоточный ключ'] as $name){$this->assertSame('tools',ItemCatalog::classify(['name'=>$name,'types'=>['barter']]));}
        foreach(['ratchet-wrench','pipe-grip-wrench'] as $normalizedName){$this->assertSame('tools',ItemCatalog::classify(['name'=>'Инструмент','normalizedName'=>$normalizedName,'types'=>['barter']]));}
        $this->assertSame('mods',ItemCatalog::classify(['name'=>'Переходник Рысь "Шарнир СВД-С" для СВДС','types'=>['mods']]));
        $this->assertSame('keys',ItemCatalog::classify(['name'=>'Ключ от двери','types'=>['keys']]));
    }
    public function test_keys_are_an_item_subcategory_without_duplicate_sidebar_or_catalog_entries(): void
    {
        $this->fixtures();$before=$this->snapshot();
        foreach(['/admin/wiki/items','/admin/wiki?group=catalogs'] as $url){
            $html=$this->get($url)->assertOk()->getContent();$dom=new \DOMDocument();@$dom->loadHTML($html);$xpath=new \DOMXPath($dom);
            $this->assertSame(0,$xpath->query('//a[@href="'.route('platform.wiki.list','keys').'"]')->length);
        }
        $this->get('/admin/wiki/items?item_group=keys')->assertOk()->assertSee('Ключ от двери')->assertDontSee('Трещоточный ключ')->assertDontSee('Шарнирный ключ КШС');
        $this->assertSame($before,$this->snapshot());
    }
    public function test_point_purpose_mode_and_named_location_filter_do_not_mix_data(): void
    {
        $this->fixtures();$before=$this->snapshot();$location=DB::table('wiki_locations')->value('id');
        $this->get('/admin/wiki/map_points?point_kind=documents')->assertOk()->assertSee('Документ у дерева')->assertDontSee('Интерактивная pve');
        foreach(['pve','regular'] as $mode){$this->get('/admin/wiki/map_points?point_kind=interactive&mode='.$mode.'&location='.$location)->assertOk()->assertSee('Интерактивная '.$mode)->assertDontSee('Документ у дерева')->assertDontSee('Интерактивная '.($mode==='pve'?'regular':'pve'));}
        $this->post('/admin/wiki/map_points/search?point_kind=documents&mode=pve',['search'=>'  дерево  ','location'=>$location])->assertRedirect();
        $this->assertSame($before,$this->snapshot());
    }
    public function test_all_reference_tabs_have_named_links_to_existing_sections(): void
    {
        $this->fixtures();$before=$this->snapshot();
        foreach(array_keys(DatasetCatalog::GROUPS) as $group){$this->get('/admin/datasets?group='.$group)->assertOk()->assertDontSee('guide/reference-pve.json')->assertDontSee('guide/bosses.json');}
        foreach(['achievements'=>'Достижения','crafts'=>'Рецепты крафтов','hideout'=>'Модули убежища','trading'=>'Бартеры торговцев','bosses'=>'Боссы и места появления'] as $group=>$title){$this->get('/admin/datasets?group='.$group)->assertOk()->assertSee($title);}
        foreach(app(DatasetCatalog::class)->rows() as $row){$this->get(route('platform.datasets.edit',['dataset'=>$row->id,'section'=>$row->section]))->assertOk();}
        foreach(array_keys(\App\Orchid\Screens\WikiHomeScreen::GROUPS) as $group){$this->get('/admin/wiki?group='.$group)->assertOk();}
        $this->assertSame($before,$this->snapshot());
    }
    public function test_invalid_new_filters_are_rejected_without_sql_or_server_errors(): void
    {
        $this->fixtures();$before=$this->snapshot();
        foreach(['/admin/quests?state[]=draft','/admin/quests?trader=not-a-trader','/admin/wiki/items?item_group=1%20OR%201=1',
            '/admin/wiki/map_points?point_kind[]=documents','/admin/wiki/map_points?location[]=1','/admin/datasets?group[]=crafts',
            '/admin/wiki?group=%3Cscript%3E'] as $url){$this->getJson($url)->assertUnprocessable();}
        $this->assertSame($before,$this->snapshot());
    }
    public function test_filters_do_not_block_navigation_but_edit_forms_keep_abandonment_protection(): void
    {
        $this->fixtures();
        foreach(['/admin/quests','/admin/wiki/map_points','/admin/datasets'] as $url){
            $html=$this->get($url)->assertOk()->assertSee('data-form-need-prevents-form-abandonment-value="false"',false)->getContent();
            $dom=new \DOMDocument();@$dom->loadHTML($html);$xpath=new \DOMXPath($dom);
            $buttons=$xpath->query('//button[contains(@formaction,"/filter") or contains(@formaction,"/search")]');
            $this->assertSame(1,$buttons->length);$this->assertSame('false',$buttons->item(0)->getAttribute('data-turbo'));
        }
        $this->get('/admin/wiki/items/create')->assertOk()->assertSee('data-form-need-prevents-form-abandonment-value="true"',false);
    }

    public function test_mobile_matrix_preserves_field_names_ids_and_read_only_navigation(): void
    {
        $this->fixtures();
        $quest=Quest::where('mode','pve')->firstOrFail();
        $payload=$quest->payload;
        $payload['objectives']=[['id'=>'stable-mobile-goal','description'=>'Длинное описание цели для мобильной формы','type'=>'visit','count'=>2]];
        $quest->payload=$payload;$quest->save();$before=$this->snapshot();
        $html=$this->get('/admin/quests/'.$quest->id.'/edit')->assertOk()
            ->assertSee('/admin-responsive.css?v=tablet-orchid-20261017a',false)->getContent();
        $dom=new \DOMDocument();@$dom->loadHTML($html);$xpath=new \DOMXPath($dom);
        $this->assertSame(1,$xpath->query('//div[@role="region" and @aria-label="Цели задания" and @tabindex="0"]/table[@data-controller="matrix"]')->length);
        $this->assertSame(1,$xpath->query('//input[@name="quest[goals][0][id]" and @value="stable-mobile-goal"]')->length);
        $this->assertSame(1,$xpath->query('//textarea[@name="quest[goals][0][description]"]')->length);
        $this->assertSame(1,$xpath->query('//select[@name="quest[goals][0][type]"]')->length);
        $this->assertSame(1,$xpath->query('//input[@name="quest[goals][0][count]" and @value="2"]')->length);
        foreach(['/admin/quests','/admin/wiki/items/create','/admin/datasets'] as $url){
            $this->get($url)->assertOk()->assertSee('/admin-responsive.css?v=tablet-orchid-20261017a',false);
        }
        $this->assertSame($before,$this->snapshot());
    }
}
