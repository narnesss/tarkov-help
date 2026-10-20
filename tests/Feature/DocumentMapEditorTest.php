<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset,User};
use App\Services\{DocumentMapEditor,WikiImporter,WikiEditor};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;

/** Проверяет визуальный редактор на изолированной БД, без реальных аккаунтов и координат. */
final class DocumentMapEditorTest extends TestCase
{
    use RefreshDatabase;
    private function fixtures(): array
    {
        $definition=['id'=>'customs','name'=>'Таможня','baseAsset'=>['type'=>'svg'],'bounds'=>[[100,0],[0,100]],
            'coordinateRotation'=>180,'baseFloor'=>['id'=>'Ground_Level'],'floors'=>[]];
        foreach(['maps.json'=>[['id'=>'customs','name'=>'Таможня','file'=>'assets/documents/kord-20261016/customs.svg','width'=>1000,'height'=>1000,
            'documentPanels'=>['floor-1'=>['x'=>50,'y'=>0,'width'=>50,'height'=>50],'floor-2'=>['x'=>0,'y'=>50,'width'=>50,'height'=>50]]]],
            'docs.json'=>[['id'=>'doc','name'=>'Письмо']],
            'spawns.json'=>[['id'=>'stable-document','map'=>'customs','doc'=>'doc','floor'=>'floor-1','caption'=>'На столе','x'=>60,'y'=>20,'x2'=>70,'y2'=>25,'images'=>['/original.webp']]],
            'raid/maps.json'=>[$definition],
            'raid/pve/customs.json'=>['mapId'=>'customs','pois'=>[['id'=>'stable-exit','name'=>'Выход','kind'=>'extract','category'=>'extract-pmc','position'=>['x'=>25,'y'=>0,'z'=>50]]]],
            'raid/customs.json'=>['mapId'=>'customs','pois'=>[['id'=>'other-mode','name'=>'Другой режим','kind'=>'extract','category'=>'extract-pmc','position'=>['x'=>25,'y'=>0,'z'=>50]]]],
        ] as $path=>$content){$dataset=new ContentDataset(['path'=>$path]);$dataset->setContent($content);$dataset->save();}
        app(WikiImporter::class)->rebuild();
        $this->actingAs(User::create(['name'=>'Редактор','email'=>'map-editor@example.test','password'=>bcrypt('test'),
            'permissions'=>['platform.index'=>true,'wiki.content'=>true]]));
        return app(DocumentMapEditor::class)->snapshot('customs','pve');
    }
    private function save(array $snapshot,array $point,array $changes=[])
    {
        return $this->postJson('/admin/document-maps/save',['map'=>'customs','mode'=>'pve','version'=>$snapshot['version'],
            'kind'=>$point['kind'],'pointId'=>$point['pointId'],'positionId'=>$point['positionId'],'floor'=>$point['floor'],
            'x'=>$point['x'],'y'=>$point['y'],...$changes]);
    }
    public function test_document_drag_converts_floor_percent_and_preserves_ids_links_and_alternate_position(): void
    {
        $snapshot=$this->fixtures();$point=collect($snapshot['points'])->firstWhere('kind','document');
        $this->assertEqualsWithDelta(20,$point['x'],.00001);$this->assertEqualsWithDelta(40,$point['y'],.00001);
        $world=DB::table('wiki_point_world_positions')->get()->all();$links=DB::table('wiki_point_documents')->get()->all();
        $result=$this->save($snapshot,$point,['x'=>80,'y'=>30,'floor'=>'floor-2'])->assertOk()->json('snapshot');
        $fresh=ContentDataset::where('path','spawns.json')->first()->content()[0];
        $this->assertSame('stable-document',$fresh['id']);$this->assertSame('floor-2',$fresh['floor']);
        $this->assertEqualsWithDelta(40,$fresh['x'],.00001);$this->assertEqualsWithDelta(65,$fresh['y'],.00001);
        $this->assertEqualsWithDelta(20,$fresh['x2'],.00001);$this->assertEqualsWithDelta(75,$fresh['y2'],.00001);
        $this->assertSame(['/original.webp'],$fresh['images']);$this->assertSame(serialize($links),serialize(DB::table('wiki_point_documents')->get()->all()));
        $this->assertSame(serialize($world),serialize(DB::table('wiki_point_world_positions')->get()->all()));$this->assertDatabaseCount('user_state',0);
        $this->assertNotSame($snapshot['version'],$result['version']);
        $this->save($snapshot,$point,['x'=>80,'y'=>30])->assertStatus(409);
        $this->assertDatabaseCount('wiki_map_points',3);
    }
    public function test_exit_position_is_independent_updates_public_cache_and_keeps_raid_unchanged(): void
    {
        $snapshot=$this->fixtures();$point=collect($snapshot['points'])->firstWhere('kind','exit');
        $raid=ContentDataset::where('path','raid/pve/customs.json')->first()->getRawOriginal('payload');
        $world=DB::table('wiki_point_world_positions')->get()->all();
        $url='/api/content/data/maps.json?view=documents&map=customs&mode=pve';
        $before=$this->getJson($url)->assertOk();$revision=$before->headers->get('X-Wiki-Content-Revision');
        $this->save($snapshot,$point,['floor'=>'floor-2','x'=>0,'y'=>100])->assertOk();
        $after=$this->getJson($url)->assertOk()->assertJsonCount(1,'exits')->assertJsonPath('exits.0.id','stable-exit')
            ->assertJsonPath('exits.0.x',0)->assertJsonPath('exits.0.y',100)->assertJsonPath('exits.0.floor','floor-2');
        $this->assertNotSame($revision,$after->headers->get('X-Wiki-Content-Revision'));
        $this->assertSame($raid,ContentDataset::where('path','raid/pve/customs.json')->first()->getRawOriginal('payload'));
        $this->assertSame(serialize($world),serialize(DB::table('wiki_point_world_positions')->get()->all()));
        $this->getJson(str_replace('mode=pve','mode=regular',$url))->assertJsonPath('exits.0.id','other-mode')->assertJsonPath('exits.0.floor','floor-1');
        app(WikiImporter::class)->rebuild();
        $this->getJson($url)->assertJsonPath('exits.0.floor','floor-2');$this->assertDatabaseCount('wiki_document_exit_positions',1);
    }
    public function test_new_document_and_exit_receive_ids_and_retry_does_not_duplicate(): void
    {
        $snapshot=$this->fixtures();$document=DB::table('wiki_documents')->value('id');
        $response=$this->postJson('/admin/document-maps/save',['map'=>'customs','mode'=>'pve','version'=>$snapshot['version'],
            'kind'=>'document','title'=>'  Новый документ  ','documentId'=>$document,'floor'=>'floor-2','x'=>30,'y'=>40])->assertOk();
        $id=$response->json('pointId');$this->assertDatabaseHas('wiki_map_points',['id'=>$id,'title'=>'Новый документ','mode'=>'all']);
        $this->assertCount(2,ContentDataset::where('path','spawns.json')->first()->content());
        $snapshot=$response->json('snapshot');$payload=['map'=>'customs','mode'=>'pve','version'=>$snapshot['version'],
            'kind'=>'exit','title'=>'Новый выход','floor'=>'floor-2','x'=>90,'y'=>10];
        $this->postJson('/admin/document-maps/save',$payload)->assertOk();$this->postJson('/admin/document-maps/save',$payload)->assertStatus(409);
        $this->assertSame(1,DB::table('wiki_map_points')->where('title','Новый выход')->count());
        $this->getJson('/api/content/data/maps.json?view=documents&map=customs&mode=pve')->assertJsonCount(2,'exits');
        $this->assertCount(1,ContentDataset::where('path','raid/pve/customs.json')->first()->content()['pois']);
    }
    public function test_permissions_bad_types_xss_and_owner_mode_checks_do_not_write(): void
    {
        $snapshot=$this->fixtures();$point=collect($snapshot['points'])->firstWhere('kind','exit');
        foreach([['x'=>[]],['x'=>'1e999'],['x'=>-1],['y'=>101],['floor'=>[]],['floor'=>'floor-99'],['pointId'=>[]],
            ['pointId'=>DB::table('wiki_map_points')->where('external_id','other-mode')->value('id')],['positionId'=>999],['unexpected'=>'value']] as $patch){
            $this->save($snapshot,$point,$patch)->assertUnprocessable();
        }
        foreach(['<img src=x onerror=alert(1)>',str_repeat('a',256),['array']] as $title){
            $this->postJson('/admin/document-maps/save',['map'=>'customs','mode'=>'pve','version'=>$snapshot['version'],
                'kind'=>'exit','title'=>$title,'floor'=>'floor-1','x'=>30,'y'=>40])->assertUnprocessable();
        }
        $this->assertDatabaseCount('wiki_document_exit_positions',0);$this->assertDatabaseCount('wiki_map_points',3);
        $this->actingAs(User::create(['name'=>'Без прав','email'=>'no-map-rights@example.test','password'=>bcrypt('test'),'permissions'=>['platform.index'=>true]]));
        $this->getJson('/admin/document-maps/data?map=customs&mode=pve')->assertForbidden();$this->save($snapshot,$point)->assertForbidden();
        $this->app['auth']->forgetGuards();$this->app['auth']->logout();
        $this->getJson('/admin/document-maps/data?map=customs&mode=pve')->assertUnauthorized();
    }
    public function test_artwork_uses_a_whitelisted_path_and_has_private_security_headers(): void
    {
        $this->fixtures();$this->get('/admin/document-maps?map=customs')->assertOk()->assertSee('Сохранить положение')->assertSee('data-form-need-prevents-form-abandonment-value="false"',false);
        $this->get('/admin/document-maps/artwork?map=customs&mode=pve')->assertOk()->assertHeader('Content-Type','image/svg+xml')->assertHeader('X-Content-Type-Options','nosniff');
        $this->getJson('/admin/document-maps/artwork?map[]=customs&mode=pve')->assertUnprocessable();
    }
    public function test_csrf_and_late_relation_failure_leave_no_partial_record(): void
    {
        $snapshot=$this->fixtures();$before=ContentDataset::orderBy('path')->pluck('payload')->all();
        DB::table('wiki_point_categories')->where('code','extract-pmc')->update(['archived'=>1]);
        $this->postJson('/admin/document-maps/save',['map'=>'customs','mode'=>'pve','version'=>$snapshot['version'],
            'kind'=>'exit','title'=>"О'Брайен'); DROP TABLE users; --",'floor'=>'floor-1','x'=>10,'y'=>20])->assertUnprocessable();
        $this->assertDatabaseCount('wiki_map_points',3);$this->assertDatabaseCount('wiki_document_exit_positions',0);
        $this->assertSame($before,ContentDataset::orderBy('path')->pluck('payload')->all());
        $this->app['env']='local';
        try{$this->postJson('/admin/document-maps/save',['map'=>'customs','mode'=>'pve'])->assertStatus(419);}
        finally{$this->app['env']='testing';}
    }
    public function test_audit_is_separate_from_content_permissions_and_contains_no_form_values(): void
    {
        $snapshot=$this->fixtures();$point=collect($snapshot['points'])->firstWhere('kind','exit');
        $this->save($snapshot,$point,['x'=>30])->assertOk();$this->save($snapshot,$point,['x'=>40])->assertStatus(409);
        $rows=DB::table('wiki_document_map_audit')->orderBy('id')->get();
        $this->assertSame(['success','denied'],$rows->pluck('result')->all());
        $this->assertSame($rows[0]->signature,$rows[1]->previous_hash);
        foreach($rows as $row){$values=(array)$row;$signature=$values['signature'];unset($values['id'],$values['signature']);
            $this->assertSame($signature,hash_hmac('sha256',implode("\n",array_map(fn($value)=>(string)$value,array_values($values))),config('app.key')));}
        $this->assertStringNotContainsString('example.test',json_encode($rows));$this->assertStringNotContainsString('Выход',json_encode($rows,JSON_UNESCAPED_UNICODE));
        $this->getJson('/admin/document-maps/audit')->assertForbidden();
        $this->actingAs(User::create(['name'=>'Аудитор','email'=>'auditor@example.test','password'=>bcrypt('test'),
            'permissions'=>['platform.index'=>true,'wiki.audit.read'=>true]]));
        $this->getJson('/admin/document-maps/audit')->assertOk()->assertJsonCount(2,'events')->assertJsonPath('nextCursor',null);
        $this->getJson('/admin/document-maps/data?map=customs&mode=pve')->assertForbidden();
    }
    public function test_remote_artwork_proxy_has_a_fixed_origin_and_rejects_arbitrary_urls(): void
    {
        $this->fixtures();$dataset=ContentDataset::where('path','maps.json')->first();$maps=$dataset->content();
        $maps[0]['layeredFile']='assets/documents/kord-levels-20261016/test.svg';$dataset->setContent($maps);$dataset->save();
        Http::fake(['https://wiki.test/assets/documents/kord-levels-20261016/test.svg'=>Http::response('<svg xmlns="http://www.w3.org/2000/svg"></svg>')]);
        $this->get('/admin/document-maps/artwork?map=customs&mode=pve')->assertOk();Http::assertSentCount(1);
        $maps[0]['layeredFile']='http://127.0.0.1/private';$dataset->setContent($maps);$dataset->save();
        $this->getJson('/admin/document-maps/artwork?map=customs&mode=pve')->assertUnprocessable();Http::assertSentCount(1);
    }
}
