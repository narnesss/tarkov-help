<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Models\ContentDataset;
use App\Services\{DocumentMapOverlay,WikiImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DocumentMapOverlayTest extends TestCase
{
    use RefreshDatabase;
    private function dataset(string $path,array $data): ContentDataset
    { $row=new ContentDataset(['path'=>$path]);$row->setContent($data);$row->save();return $row; }
    private function fixtures(): array
    {
        $definition=['id'=>'customs','name'=>'Таможня','baseAsset'=>['type'=>'svg'],'bounds'=>[[100,0],[0,100]],'coordinateRotation'=>180,'baseFloor'=>['id'=>'Ground_Level'],'floors'=>[]];
        $this->dataset('maps.json',[['id'=>'customs','name'=>'Таможня','file'=>'/map.svg','width'=>1000,'height'=>1000]]);
        $this->dataset('docs.json',[['id'=>'doc','name'=>'Документ']]);
        $this->dataset('guide/items-pve.json',['items'=>[['id'=>'door-key','name'=>'Ключ от комнаты','iconLink'=>'/assets/key.webp']]]);
        $this->dataset('spawns.json',[['id'=>'point','map'=>'customs','doc'=>'doc','caption'=>'Комната','x'=>20,'y'=>30,'keyIds'=>['door-key']]]);
        $this->dataset('raid/maps.json',[$definition]);
        $exit=['id'=>'exit','kind'=>'extract','category'=>'extract-pmc','name'=>'Выход <script>','position'=>['x'=>25,'y'=>0,'z'=>50],'conditions'=>['Выход связан с рубильником. Проверьте питание в рейде.','Условие <img>']];
        $this->dataset('raid/pve/customs.json',['mapId'=>'customs','pois'=>[$exit,[...$exit,'id'=>'scav','category'=>'extract-scav'],[...$exit,'id'=>'outside','position'=>['x'=>999,'y'=>0,'z'=>50]]]]);
        $this->dataset('raid/customs.json',['mapId'=>'customs','pois'=>[[...$exit,'id'=>'pvp']]]);
        app(WikiImporter::class)->rebuild();return $definition;
    }
    public function test_access_rules_exclude_bypasses_and_preserve_explicit_relations(): void
    {
        $service=new DocumentMapOverlay();
        foreach(['У запертого бункера','Рядом с запертой лабораторией на «Ключ»','Заперт на замок, который ломается от выстрела','Комната с кодовой панелью','Запертая комната, попасть можно через окно'] as $text){$this->assertFalse($service->access(['caption'=>$text])['requiresKey'],$text);}
        $key=['id'=>'key','name'=>'Ключ от комнаты','iconLink'=>'/key.webp'];
        $this->assertSame([$key],$service->access(['caption'=>'В комнате запертой на «Ключ от комнаты»'],null,[$key])['keys']);
        $factory=['id'=>'factory-key','name'=>'Ключ от выхода с Завода','iconLink'=>'/factory-key.webp'];
        $this->assertSame([$factory],$service->access(['caption'=>'Комната запертая на «Ключ от Завода»'],null,[$factory])['keys']);
        $warehouse=['id'=>'warehouse-key','name'=>'Ключ-карта от склада TerraGroup','iconLink'=>'/warehouse-key.png'];
        $this->assertSame([$warehouse],$service->access(['caption'=>'Комната закрыта на ключ-карту «Полихим»'],null,[$warehouse])['keys']);
        $this->assertSame('Ключ-карта «Полихим»',$service->access(['caption'=>'Комната закрыта на ключ-карту «Полихим»'])['keys'][0]['name']);
        $this->assertFalse($service->access(['caption'=>'Заперта на ключ','cmsKeyUsages'=>[]])['requiresKey']);
        $this->assertFalse($service->access([], [['item'=>'key','usage_type'=>'optional']])['requiresKey']);
        $this->assertTrue($service->access([], [['item'=>'key','name'=>'Ключ','usage_type'=>'unlock'],['item'=>'extra','usage_type'=>'optional']])['requiresKey']);
    }
    public function test_endpoint_filters_owner_mode_faction_and_bounds_without_writes(): void
    {
        $this->fixtures();$refs=ContentDataset::orderBy('path')->pluck('payload')->all();
        $url='/api/content/data/maps.json?view=documents&map=customs&mode=pve';
        $response=$this->getJson($url)->assertOk()->assertHeaderMissing('Set-Cookie')->assertJsonPath('mapId','customs')->assertJsonPath('mode','pve')
            ->assertJsonCount(1,'exits')->assertJsonCount(1,'exits.0.conditions')->assertJsonPath('exits.0.conditions.0','Условие <img>')->assertJsonPath('exits.0.id','exit')->assertJsonPath('exits.0.x',fn($x)=>abs($x-75)<.00001)->assertJsonPath('exits.0.y',fn($y)=>abs($y-50)<.00001)
            ->assertJsonPath('access.point.requiresKey',true)->assertJsonPath('access.point.keys.0.name','Ключ от комнаты')->assertJsonPath('access.point.keys.0.iconLink','/assets/key.webp');
        $revision=$response->headers->get('X-Wiki-Content-Revision');$this->getJson($url)->assertHeader('X-Wiki-Content-Cache','HIT');
        $this->getJson(str_replace('mode=pve','mode=regular',$url))->assertJsonPath('exits.0.id','pvp');
        $this->assertSame($refs,ContentDataset::orderBy('path')->pluck('payload')->all());$this->assertDatabaseCount('user_state',0);
        DB::table('wiki_items')->where('external_id','door-key')->update(['name'=>'Новое имя ключа']);
        $updated=$this->getJson($url)->assertJsonPath('access.point.keys.0.name','Новое имя ключа');$this->assertNotSame($revision,$updated->headers->get('X-Wiki-Content-Revision'));
        $dataset=ContentDataset::where('path','raid/pve/customs.json')->first();$dataset->setContent(['mapId'=>'woods','pois'=>[['id'=>'foreign','kind'=>'extract','category'=>'extract-pmc','position'=>['x'=>20,'y'=>0,'z'=>20]]]]);$dataset->save();
        $this->getJson($url)->assertJsonCount(0,'exits');
        $this->getJson('/api/content/data/maps.json?view=documents&map[]=customs')->assertStatus(422);
        $this->getJson('/api/content/data/maps.json?view=documents&map=customs&mode=bad')->assertStatus(422);
        $this->getJson('/api/content/data/maps.json?view=documents&map=unknown')->assertNotFound();
        $this->getJson('/api/content/data/maps.json')->assertJsonPath('0.id','customs');
    }
    public function test_special_calibrations_place_known_exit_landmarks(): void
    {
        $service=new DocumentMapOverlay();
        foreach(['icebreaker'=>[['x'=>.28,'y'=>22.56,'z'=>-66.03],60.6,17], 'labyrinth'=>[['x'=>19.44,'y'=>1.07,'z'=>.5],37.2,65.2]] as $id=>[$position,$x,$y]){
            $raid=DocumentMapOverlay::ALIASES[$id]??$id;
            $data=$service->build(['id'=>$id],[],['id'=>$raid],['mapId'=>$raid,'pois'=>[['id'=>'landmark','kind'=>'extract','category'=>'extract-pmc','position'=>$position]]],[]);
            $this->assertEqualsWithDelta($x,$data['exits'][0]['x'],1);$this->assertEqualsWithDelta($y,$data['exits'][0]['y'],1);
        }
    }
    public function test_verified_edge_exits_keep_coordinates_and_explicit_floor_wins(): void
    {
        $service=new DocumentMapOverlay();
        foreach(['customs'=>[[[698,-307],[-372,237]],['x'=>140.63,'y'=>3.75,'z'=>-322.83]],'woods'=>[[[646,-914],[-761,442]],['x'=>-142.5,'y'=>-2.37,'z'=>447.58]]] as $id=>[$bounds,$position]){
            $definition=['id'=>$id,'baseAsset'=>['type'=>'svg'],'bounds'=>$bounds,'coordinateRotation'=>180,'baseFloor'=>['id'=>'Ground_Level']];
            $point=['id'=>'edge','kind'=>'extract','category'=>'extract-pmc','position'=>$position,'sourceStatus'=>'current'];
            $result=$service->build(['id'=>$id],[],$definition,['mapId'=>$id,'pois'=>[$point]],[]);
            $this->assertCount(1,$result['exits']);$this->assertTrue($result['exits'][0]['outsideArtwork']);
            $this->assertTrue($result['exits'][0]['y']<0||$result['exits'][0]['y']>100);
            $point['sourceStatus']='unknown';$this->assertCount(0,$service->build(['id'=>$id],[],$definition,['mapId'=>$id,'pois'=>[$point]],[])['exits']);
        }
        $definition=['id'=>'streets-of-tarkov','baseAsset'=>['type'=>'svg'],'bounds'=>[[100,0],[0,100]],'coordinateRotation'=>180,'baseFloor'=>['id'=>'Ground_Level'],
            'floors'=>[['id'=>'Underground_Level','extents'=>[['height'=>[-100,-6]]]]]];
        $point=['id'=>'checkpoint','kind'=>'extract','category'=>'extract-pmc','position'=>['x'=>20,'y'=>-8.16,'z'=>30],'floorOverride'=>'Ground_Level'];
        $result=$service->build(['id'=>'streets'],[],$definition,['mapId'=>'streets-of-tarkov','pois'=>[$point]],[]);
        $this->assertSame('floor-1',$result['exits'][0]['floor']);
    }

    public function test_independent_sql_exit_position_overrides_only_the_document_view(): void
    {
        $this->fixtures();$maps=\App\Models\ContentDataset::where('path','maps.json')->first();$data=$maps->content();
        $data[0]['documentPanels']=['floor-1'=>['x'=>0,'y'=>0,'width'=>100,'height'=>100],'floor--1'=>['x'=>0,'y'=>0,'width'=>100,'height'=>100]];
        $maps->setContent($data);$maps->save();$mapId=DB::table('wiki_maps')->where('slug','documents')->value('id');
        $pointId=DB::table('wiki_map_points')->where('external_id','exit')->where('mode','pve')->value('id');
        $layer=app(\App\Services\WikiStore::class)->put('map_layers',['map_id'=>$mapId,'external_id'=>'floor--1'],['name'=>'Подземный этаж']);
        $url='/api/content/data/maps.json?view=documents&map=customs&mode=pve';
        $revision=$this->getJson($url)->assertOk()->headers->get('X-Wiki-Content-Revision');
        $before=serialize(DB::table('wiki_point_world_positions')->get()->all());
        DB::table('wiki_document_exit_positions')->insert(['point_id'=>$pointId,'map_id'=>$mapId,'layer_id'=>$layer,'x_percent'=>25,'y_percent'=>10]);
        $response=$this->getJson($url)->assertOk()->assertJsonCount(1,'exits')->assertJsonPath('exits.0.id','exit')
            ->assertJsonPath('exits.0.x',25)->assertJsonPath('exits.0.y',10)->assertJsonPath('exits.0.floor','floor--1');
        $this->assertNotSame($revision,$response->headers->get('X-Wiki-Content-Revision'));
        $this->getJson(str_replace('mode=pve','mode=regular',$url))->assertJsonPath('exits.0.id','pvp');
        $this->assertSame($before,serialize(DB::table('wiki_point_world_positions')->get()->all()));
    }

    public function test_factory_document_map_keeps_all_seven_pmc_exits_on_their_floors_in_both_modes(): void
    {
        $definition=collect(json_decode(file_get_contents(public_path('data/raid/maps.json')),true,flags:JSON_THROW_ON_ERROR))->firstWhere('id','factory');
        $service=new DocumentMapOverlay();$byMode=[];
        foreach(['regular','pve'] as $mode){
            $path=public_path('data/raid/'.($mode==='pve'?'pve/':'').'factory.json.gz');
            $raid=json_decode(gzdecode(file_get_contents($path)),true,flags:JSON_THROW_ON_ERROR);
            $exits=array_column($service->build(['id'=>'factory'],[],$definition,$raid,[])['exits'],null,'name');
            $this->assertCount(7,$exits);
            foreach(['Ворота у медицинской палатки','Ворота 3','Ворота во дворе','Ворота 2','Ворота 0'] as $name){
                $this->assertSame('floor-1',$exits[$name]['floor'],$mode.': '.$name);
            }
            foreach(['Подвалы','Проход Контрабандистов'] as $name)$this->assertSame('floor--1',$exits[$name]['floor']);
            $this->assertArrayNotHasKey('Офисное окно',$exits);
            $this->assertTrue($exits['Ворота во дворе']['outsideArtwork']);
            $this->assertEqualsWithDelta(-1.16755,$exits['Ворота во дворе']['x'],.00001);
            $this->assertEqualsWithDelta(3.10842,$exits['Ворота 3']['x'],.00001);
            $byMode[$mode]=$exits;
        }
        $this->assertSame($byMode['regular'],$byMode['pve']);
    }

    public function test_shoreline_document_exit_keeps_its_own_floor_on_both_api_generations(): void
    {
        $definition=['id'=>'shoreline','baseAsset'=>['type'=>'svg'],'bounds'=>[[504,-415],[-1056,618]],'coordinateRotation'=>180,
            'baseFloor'=>['id'=>'Ground_Level'],'floors'=>[['id'=>'Second_Floor','extents'=>[['height'=>[-1,2]]]]]];
        $point=['id'=>'trail','name'=>'Тропа альпиниста','kind'=>'extract','category'=>'extract-pmc',
            'position'=>['x'=>-214.3,'y'=>-1,'z'=>-361.8],'documentFloorOverride'=>'Ground_Level'];
        $service=new DocumentMapOverlay();
        foreach([[],['floorOverride'=>'Second_Floor'],['documentFloorOverride'=>'Прежний этаж','floorOverride'=>'Ground_Level']] as $legacy){
            $result=$service->build(['id'=>'shoreline'],[],$definition,['mapId'=>'shoreline','pois'=>[[...$point,...$legacy]]],[]);
            $this->assertCount(1,$result['exits']);$this->assertSame('floor-1',$result['exits'][0]['floor']);
            $this->assertEqualsWithDelta(46.04487,$result['exits'][0]['x'],.00001);
            $this->assertEqualsWithDelta(5.15005,$result['exits'][0]['y'],.00001);
        }
    }

}
