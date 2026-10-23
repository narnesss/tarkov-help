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

    public function test_labyrinth_document_map_projects_both_exits_from_tiles_in_both_modes(): void
    {
        $definition=collect(json_decode(file_get_contents(public_path('data/raid/maps.json')),true,flags:JSON_THROW_ON_ERROR))->firstWhere('id','the-labyrinth');
        $this->assertSame('the-labyrinth',DocumentMapOverlay::ALIASES['labyrinth']);
        $this->assertSame('tiles',$definition['baseAsset']['type']);
        $service=new DocumentMapOverlay();$byMode=[];
        foreach(['regular','pve'] as $mode){
            $path=public_path('data/raid/'.($mode==='pve'?'pve/':'').'the-labyrinth.json.gz');
            $raid=json_decode(gzdecode(file_get_contents($path)),true,flags:JSON_THROW_ON_ERROR);$before=$raid;
            $exits=array_column($service->build(['id'=>'labyrinth'],[],$definition,$raid,[])['exits'],null,'name');
            $this->assertCount(2,$exits);
            foreach(['Путь наверх'=>[64.85246,43.88459],'Тропа Ариадны'=>[37.18720,65.25384]] as $name=>[$x,$y]){
                $this->assertSame('floor-1',$exits[$name]['floor']);
                $this->assertEqualsWithDelta($x,$exits[$name]['x'],.00001);
                $this->assertEqualsWithDelta($y,$exits[$name]['y'],.00001);
            }
            $this->assertSame([],$exits['Путь наверх']['conditions']);
            $this->assertSame(['Требуется: Ключ с меткой Ариадны'],$exits['Тропа Ариадны']['conditions']);
            $this->assertSame($before,$raid);$byMode[$mode]=$exits;
        }
        $this->assertSame($byMode['regular'],$byMode['pve']);
    }

    public function test_interchange_document_exits_use_parking_level_in_both_modes(): void
    {
        $definition=collect(json_decode(file_get_contents(public_path('data/raid/maps.json')),true,flags:JSON_THROW_ON_ERROR))->firstWhere('id','interchange');
        $service=new DocumentMapOverlay();$byMode=[];
        $landmarks=['Безопасная комната'=>[62.63143,55.99770],'Выход на железной дороге'=>[11.94762,.92281],
            'КПП МЧС'=>[89.19108,81.65207],'Лагерь Диких (Совм.)'=>[67.45684,42.50115],
            'Тропа к реке (Сигнал)'=>[-1.54219,59.13594],'А-Выход у ТЭЦ'=>[87.38021,8.55645],
            'Дыра в заборе'=>[79.33269,46.65668]];
        foreach(['regular','pve'] as $mode){
            $raid=json_decode(gzdecode(file_get_contents(public_path('data/raid/'.($mode==='pve'?'pve/':'').'interchange.json.gz'))),true,flags:JSON_THROW_ON_ERROR);$before=$raid;
            $exits=array_column($service->build(['id'=>'interchange'],[],$definition,$raid,[])['exits'],null,'name');
            $this->assertCount(7,$exits);
            foreach($landmarks as $name=>[$x,$y]){
                $this->assertSame('floor--1',$exits[$name]['floor'],$mode.': '.$name);
                $this->assertEqualsWithDelta($x,$exits[$name]['x'],.00001);$this->assertEqualsWithDelta($y,$exits[$name]['y'],.00001);
                $this->assertSame(collect($raid['pois'])->firstWhere('id',$exits[$name]['id'])['conditions'],$exits[$name]['conditions']);
            }
            $this->assertTrue($exits['Тропа к реке (Сигнал)']['outsideArtwork']);
            $this->assertCount(1,array_filter($exits,static fn($exit)=>$exit['category']==='extract-shared'));
            $this->assertSame($before,$raid);$byMode[$mode]=$exits;
        }
        $this->assertSame($byMode['regular'],$byMode['pve']);
    }

    public function test_interchange_floor_aliases_keep_mall_levels_separate_and_invalidate_cached_overlay(): void
    {
        $definition=collect(json_decode(file_get_contents(public_path('data/raid/maps.json')),true,flags:JSON_THROW_ON_ERROR))->firstWhere('id','interchange');
        $points=[];foreach(['parking'=>23,'first'=>28,'second'=>35] as $id=>$height)$points[]=['id'=>$id,'kind'=>'extract','category'=>'extract-pmc','sourceStatus'=>'current','position'=>['x'=>-47.73,'y'=>$height,'z'=>44.06]];
        $points[]=[...$points[2],'id'=>'override','documentFloorOverride'=>'First_Floor','floorOverride'=>'Ground_Level'];
        $this->dataset('maps.json',[['id'=>'interchange','name'=>'Развязка']]);$this->dataset('spawns.json',[]);
        $this->dataset('raid/maps.json',[$definition]);$this->dataset('raid/pve/interchange.json',['mapId'=>'interchange','pois'=>$points]);
        $url='/api/content/data/maps.json?view=documents&map=interchange&mode=pve';$aliases=config('document-overlays.floorAliases');
        config(['document-overlays.floorAliases'=>[]]);
        $old=$this->getJson($url)->assertOk()->assertJsonPath('exits.0.floor','floor-1');
        $this->getJson($url)->assertHeader('X-Wiki-Content-Cache','HIT');
        config(['document-overlays.floorAliases'=>$aliases]);
        $new=$this->getJson($url)->assertOk()->assertJsonPath('exits.0.floor','floor--1')->assertJsonPath('exits.1.floor','floor-1')
            ->assertJsonPath('exits.2.floor','floor-2')->assertJsonPath('exits.3.floor','floor-1');
        $this->assertNotSame($old->headers->get('X-Wiki-Content-Revision'),$new->headers->get('X-Wiki-Content-Revision'));
    }

    public function test_lighthouse_document_map_keeps_all_seven_current_exits_in_both_modes(): void
    {
        $definition=collect(json_decode(file_get_contents(public_path('data/raid/maps.json')),true,flags:JSON_THROW_ON_ERROR))->firstWhere('id','lighthouse');
        $this->assertSame('svg',$definition['baseAsset']['type']);
        $service=new DocumentMapOverlay();$byMode=[];
        $landmarks=['Бронепоезд'=>[47.99340,7.45908],'Южная дорога'=>[76.49057,82.33314],
            'Тропа через перевал'=>[66.36887,54.17934],'Проход у озера'=>[84.17830,20.59257],
            'Северный блокпост'=>[37.76698,0.49565],'Боковой тоннель (Совм.)'=>[55.02830,76.38479],
            'Переход на Берег'=>[82.96226,50.87638]];
        foreach(['regular','pve'] as $mode){
            $path=public_path('data/raid/'.($mode==='pve'?'pve/':'').'lighthouse.json.gz');
            $raid=json_decode(gzdecode(file_get_contents($path)),true,flags:JSON_THROW_ON_ERROR);$before=$raid;
            $exits=array_column($service->build(['id'=>'lighthouse'],[],$definition,$raid,[])['exits'],null,'name');
            $this->assertCount(7,$exits);
            foreach($landmarks as $name=>[$x,$y]){
                $this->assertSame('floor-1',$exits[$name]['floor'],$mode.': '.$name);
                $this->assertEqualsWithDelta($x,$exits[$name]['x'],.00001);
                $this->assertEqualsWithDelta($y,$exits[$name]['y'],.00001);
                $original=collect($raid['pois'])->firstWhere('id',$exits[$name]['id']);
                $this->assertSame($original['conditions'],$exits[$name]['conditions']);
            }
            $this->assertArrayNotHasKey('Road to Military Base V-Ex',$exits);
            $this->assertCount(2,array_filter($exits,static fn($exit)=>$exit['category']==='extract-shared'));
            $this->assertSame($before,$raid);$byMode[$mode]=$exits;
        }
        $this->assertSame($byMode['regular'],$byMode['pve']);
    }

    public function test_woods_document_map_keeps_all_ten_exits_and_the_verified_edge_in_both_modes(): void
    {
        $definition=collect(json_decode(file_get_contents(public_path('data/raid/maps.json')),true,flags:JSON_THROW_ON_ERROR))->firstWhere('id','woods');
        $this->assertSame('svg',$definition['baseAsset']['type']);
        $service=new DocumentMapOverlay();$byMode=[];
        $landmarks=['ЗБ-016'=>[73.57214,68.21829],'Проход у ЛЭП (Сигнал)'=>[5.11798,61.11652],
            'Окраины'=>[21.10590,93.84808],'Южный пост ООН'=>[84.01706,88.55236],
            'Ворота ВС РФ'=>[56.04122,100.41150],'ЗБ-014'=>[14.12651,71.64159],
            'Мост дружбы (Совм.)'=>[39.29140,5.16372],'ЖД-мост к Таркову'=>[97.82729,76.94100],
            'Северный пост ООН'=>[85.52381,62.45944],'А-Выход на мосту'=>[80.40725,30.23156]];
        foreach(['regular','pve'] as $mode){
            $path=public_path('data/raid/'.($mode==='pve'?'pve/':'').'woods.json.gz');
            $raid=json_decode(gzdecode(file_get_contents($path)),true,flags:JSON_THROW_ON_ERROR);$before=$raid;
            $exits=array_column($service->build(['id'=>'woods'],[],$definition,$raid,[])['exits'],null,'name');
            $this->assertCount(10,$exits);
            foreach($landmarks as $name=>[$x,$y]){
                $this->assertSame('floor-1',$exits[$name]['floor'],$mode.': '.$name);
                $this->assertEqualsWithDelta($x,$exits[$name]['x'],.00001);
                $this->assertEqualsWithDelta($y,$exits[$name]['y'],.00001);
                $original=collect($raid['pois'])->firstWhere('id',$exits[$name]['id']);
                $this->assertSame($original['conditions'],$exits[$name]['conditions']);
            }
            $this->assertTrue($exits['Ворота ВС РФ']['outsideArtwork']);
            $this->assertCount(1,array_filter($exits,static fn($exit)=>$exit['category']==='extract-shared'));
            $this->assertSame($before,$raid);
            $byMode[$mode]=array_map(static fn($exit)=>array_intersect_key($exit,array_flip(['id','x','y','floor','category','outsideArtwork'])),$exits);
        }
        $this->assertSame($byMode['regular'],$byMode['pve']);
    }

    public function test_lab_document_map_projects_all_seven_exits_onto_three_levels_in_both_modes(): void
    {
        $definition=collect(json_decode(file_get_contents(public_path('data/raid/maps.json')),true,flags:JSON_THROW_ON_ERROR))->firstWhere('id','the-lab');
        $this->assertSame('the-lab',DocumentMapOverlay::ALIASES['lab']);
        $this->assertSame('tiles',$definition['baseAsset']['type']);
        $service=new DocumentMapOverlay();$byMode=[];
        $landmarks=['Ангар'=>['floor-1',90.91075,56.29672],'Парковка'=>['floor-1',6.78547,26.84566],
            'Грузовой лифт'=>['floor-2',24.11736,84.67709],'Лифт медблока'=>['floor--1',46.83369,84.42665],
            'Вентиляционная шахта'=>['floor--1',28.24448,68.59755],'Центральный лифт'=>['floor--1',50.11247,2.07950],
            'Накопительный коллектор'=>['floor--1',77.01885,79.30341]];
        foreach(['regular','pve'] as $mode){
            $path=public_path('data/raid/'.($mode==='pve'?'pve/':'').'the-lab.json.gz');
            $raid=json_decode(gzdecode(file_get_contents($path)),true,flags:JSON_THROW_ON_ERROR);$before=$raid;
            $exits=array_column($service->build(['id'=>'lab'],[],$definition,$raid,[])['exits'],null,'name');
            $this->assertCount(7,$exits);
            foreach($landmarks as $name=>[$floor,$x,$y]){
                $this->assertSame($floor,$exits[$name]['floor'],$mode.': '.$name);
                $this->assertEqualsWithDelta($x,$exits[$name]['x'],.00001);
                $this->assertEqualsWithDelta($y,$exits[$name]['y'],.00001);
            }
            $this->assertCount(3,array_filter($exits,static fn($exit)=>$exit['category']==='extract-shared'));
            $this->assertSame($before,$raid);$byMode[$mode]=$exits;
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

    public function test_reserve_document_map_keeps_all_seven_exits_on_surface_and_in_bunkers_in_both_modes(): void
    {
        $definition=collect(json_decode(file_get_contents(public_path('data/raid/maps.json')),true,flags:JSON_THROW_ON_ERROR))->firstWhere('id','reserve');
        $service=new DocumentMapOverlay();$byMode=[];
        $landmarks=['Бронепоезд'=>['floor-1',25.02872,23.39744],'Выход в сторону Леса'=>['floor-1',42.64696,9.60440],
            'Спуск со скалы'=>['floor-1',50.37838,88.35714],'Канализационный люк'=>['floor-1',42.03041,64.18498],
            'Д-2'=>['floor--1',69.33784,81.73077],'Гермозатвор бункера'=>['floor--1',38.35811,15.28571],
            'Дикие места (Совм.)'=>['floor-1',70.42230,24.06777]];
        foreach(['regular','pve'] as $mode){
            $raid=json_decode(gzdecode(file_get_contents(public_path('data/raid/'.($mode==='pve'?'pve/':'').'reserve.json.gz'))),true,flags:JSON_THROW_ON_ERROR);$before=$raid;
            $exits=array_column($service->build(['id'=>'reserve'],[],$definition,$raid,[])['exits'],null,'name');
            $this->assertCount(7,$exits);
            foreach($landmarks as $name=>[$floor,$x,$y]){
                $this->assertSame($floor,$exits[$name]['floor'],$mode.': '.$name);
                $this->assertEqualsWithDelta($x,$exits[$name]['x'],.00001);$this->assertEqualsWithDelta($y,$exits[$name]['y'],.00001);
                $this->assertSame(collect($raid['pois'])->firstWhere('id',$exits[$name]['id'])['conditions'],$exits[$name]['conditions']);
            }
            $this->assertCount(4,array_filter($exits,static fn($exit)=>$exit['category']==='extract-shared'));
            $this->assertSame($before,$raid);$byMode[$mode]=$exits;
        }
        $this->assertSame($byMode['regular'],$byMode['pve']);
    }

    public function test_customs_document_map_keeps_all_eleven_exits_and_explicit_floors_in_both_modes(): void
    {
        $definition=collect(json_decode(file_get_contents(public_path('data/raid/maps.json')),true,flags:JSON_THROW_ON_ERROR))->firstWhere('id','customs');
        $service=new DocumentMapOverlay();$byMode=[];
        $landmarks=['Перекресток'=>['floor-1',96.66636,40.55331],'Блокпост ВС РФ'=>['floor-1',66.19159,30.98346],
            'Подвал котельной (Совм.)'=>['floor-1',55.23645,48.41544],'Проход через ЖД пути (Сигнал)'=>['floor-1',52.09065,-2.90993],
            'ЗБ-1013'=>['floor-1',46.45047,28.29228],'ЗБ-1011'=>['floor--1',7.14953,33.18382],
            'Трейлерный парк'=>['floor-1',94.77103,13.57904],'А-Выход у общаги'=>['floor-1',48.31028,95.63419],
            'Старая заправка'=>['floor-1',36.22523,24.42647],'Лодка Контрабандистов'=>['floor-1',69.11308,78.98346],
            'Бункер Контрабандистов (ЗБ-1012)'=>['floor-1',22.01215,35.79963]];
        foreach(['regular','pve'] as $mode){
            $raid=json_decode(gzdecode(file_get_contents(public_path('data/raid/'.($mode==='pve'?'pve/':'').'customs.json.gz'))),true,flags:JSON_THROW_ON_ERROR);$before=$raid;
            $exits=array_column($service->build(['id'=>'customs'],[],$definition,$raid,[])['exits'],null,'name');
            $this->assertCount(11,$exits);
            foreach($landmarks as $name=>[$floor,$x,$y]){
                $this->assertSame($floor,$exits[$name]['floor'],$mode.': '.$name);
                $this->assertEqualsWithDelta($x,$exits[$name]['x'],.00001);$this->assertEqualsWithDelta($y,$exits[$name]['y'],.00001);
                $original=collect($raid['pois'])->firstWhere('id',$exits[$name]['id']);
                $conditions=array_values(array_filter($original['conditions'],static fn($text)=>$text!=='Выход связан с рубильником. Проверьте питание в рейде.'));
                $this->assertSame($conditions,$exits[$name]['conditions']);
            }
            $this->assertTrue($exits['Проход через ЖД пути (Сигнал)']['outsideArtwork']);
            $this->assertCount(1,array_filter($exits,static fn($exit)=>$exit['category']==='extract-shared'));
            $this->assertSame($before,$raid);$byMode[$mode]=$exits;
        }
        $this->assertSame($byMode['regular'],$byMode['pve']);
    }

    public function test_streets_document_map_keeps_twelve_exits_and_mode_specific_taxi_costs(): void
    {
        $definition=collect(json_decode(file_get_contents(public_path('data/raid/maps.json')),true,flags:JSON_THROW_ON_ERROR))->firstWhere('id','streets-of-tarkov');
        $this->assertSame('streets-of-tarkov',DocumentMapOverlay::ALIASES['streets']);$service=new DocumentMapOverlay();$byMode=[];
        $landmarks=['Упавший кран'=>['floor-1',17.72471,68.64571],'Место крушения'=>['floor-1',1.70149,84.75937],
            'Разрушенный дом'=>['floor-1',94.26368,77.36638],'Подвал в Pinewood (Совм.)'=>['floor-1',72.51741,43.51632],
            'Лифт в стилобате'=>['floor-1',60.98507,26.94680],'Улица Климова (Сигнал)'=>['floor-1',97.24876,40.89359],
            'Двор-колодец'=>['floor-1',78.52570,96.14631],'КПП у выставочного центра'=>['floor-1',18.22222,22.97944],
            'Подвал Контрабандистов'=>['floor-1',40.96352,41.71826],'Река в коллекторе'=>['floor-1',97.85075,62.21040],
            'Блокпост Диких'=>['floor--1',18.51741,45.62394],'А-выход Такси на Приморском'=>['floor-1',53.92869,91.44256]];
        foreach(['regular','pve'] as $mode){
            $raid=json_decode(gzdecode(file_get_contents(public_path('data/raid/'.($mode==='pve'?'pve/':'').'streets-of-tarkov.json.gz'))),true,flags:JSON_THROW_ON_ERROR);$before=$raid;
            $exits=array_column($service->build(['id'=>'streets'],[],$definition,$raid,[])['exits'],null,'name');
            $this->assertCount(12,$exits);
            foreach($landmarks as $name=>[$floor,$x,$y]){
                $this->assertSame($floor,$exits[$name]['floor'],$mode.': '.$name);
                $this->assertEqualsWithDelta($x,$exits[$name]['x'],.00001);$this->assertEqualsWithDelta($y,$exits[$name]['y'],.00001);
                $this->assertSame(collect($raid['pois'])->firstWhere('id',$exits[$name]['id'])['conditions'],$exits[$name]['conditions']);
            }
            $this->assertSame([$mode==='regular'?'Требуется: Рубли ×20 000':'Требуется: Рубли ×5 000'],$exits['А-выход Такси на Приморском']['conditions']);
            $this->assertCount(1,array_filter($exits,static fn($exit)=>$exit['category']==='extract-shared'));
            $this->assertSame($before,$raid);$byMode[$mode]=$exits;
        }
        $geometry=static fn($exit)=>array_diff_key($exit,['conditions'=>true]);
        $this->assertSame(array_map($geometry,$byMode['regular']),array_map($geometry,$byMode['pve']));
    }

    public function test_ground_zero_document_exits_keep_six_current_positions_and_mode_specific_costs(): void
    {
        $definition=collect(json_decode(file_get_contents(public_path('data/raid/maps.json')),true,flags:JSON_THROW_ON_ERROR))->firstWhere('id','ground-zero');
        $this->assertSame('ground-zero',DocumentMapOverlay::ALIASES['groundzero']);$service=new DocumentMapOverlay();$byMode=[];
        $landmarks=['Спуск в подвал в Nakatani'=>[76.18391,94.06967],'Блокпост Диких (Совм.)'=>[64.30460,9.11066],
            'Офис продаж Tartowers'=>[16.72414,52.74385],'Проспект Мира (Сигнал)'=>[8.84195,17.51844],
            'Пункт МЧС'=>[27.97989,5.43852],'А-Выход полицейский кордон'=>[77.15805,48.96311]];
        foreach(['regular','pve'] as $mode){
            $raid=json_decode(gzdecode(file_get_contents(public_path('data/raid/'.($mode==='pve'?'pve/':'').'ground-zero.json.gz'))),true,flags:JSON_THROW_ON_ERROR);$before=$raid;
            $exits=array_column($service->build(['id'=>'groundzero'],[],$definition,$raid,[])['exits'],null,'name');$this->assertCount(6,$exits);
            foreach($landmarks as $name=>[$x,$y]){
                $this->assertSame('floor-1',$exits[$name]['floor'],$mode.': '.$name);
                $this->assertEqualsWithDelta($x,$exits[$name]['x'],.00001);$this->assertEqualsWithDelta($y,$exits[$name]['y'],.00001);
                $this->assertSame(collect($raid['pois'])->firstWhere('id',$exits[$name]['id'])['conditions'],$exits[$name]['conditions']);
            }
            $this->assertArrayNotHasKey('Подвал в Pinewood (Совм.)',$exits);$this->assertArrayNotHasKey('Южный пост ООН',$exits);
            $this->assertSame([$mode==='regular'?'Требуется: Рубли ×20 000':'Требуется: Рубли ×5 000'],$exits['А-Выход полицейский кордон']['conditions']);
            $this->assertCount(3,array_filter($exits,static fn($exit)=>$exit['category']==='extract-shared'));
            $this->assertSame($before,$raid);$byMode[$mode]=$exits;
        }
        $geometry=static fn($exit)=>array_diff_key($exit,['conditions'=>true]);$this->assertSame(array_map($geometry,$byMode['regular']),array_map($geometry,$byMode['pve']));
    }

}
