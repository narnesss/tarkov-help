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
        $exit=['id'=>'exit','kind'=>'extract','category'=>'extract-pmc','name'=>'Выход <script>','position'=>['x'=>25,'y'=>0,'z'=>50],'conditions'=>['Условие <img>']];
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
            ->assertJsonCount(1,'exits')->assertJsonPath('exits.0.id','exit')->assertJsonPath('exits.0.x',fn($x)=>abs($x-75)<.00001)->assertJsonPath('exits.0.y',fn($y)=>abs($y-50)<.00001)
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
}
