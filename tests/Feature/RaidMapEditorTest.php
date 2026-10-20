<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset,User};
use App\Services\{RaidMapEditor,WikiImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Реальные HTTP-запросы редактора, CAS, ошибочные типы и сохранность проекции. */
final class RaidMapEditorTest extends TestCase
{
    use RefreshDatabase;
    private function dataset(string $path,array $content): void
    { $d=new ContentDataset(['path'=>$path]);$d->setContent($content);$d->save(); }
    private function fixtures(): void
    {
        $this->dataset('raid/maps.json',[['id'=>'factory','name'=>'Завод','displayName'=>'Завод','bounds'=>[[100,-100],[-100,100]],'baseAsset'=>['type'=>'svg','path'=>'assets/raid-maps/svg/Factory.svg'],
            'baseFloor'=>['id'=>'base','name'=>'Первый'],'floors'=>[['id'=>'upper','name'=>'Второй','extents'=>[['height'=>[3,6]]]]]]]);
        foreach(['regular','pve'] as $mode){$this->dataset('raid/'.($mode==='pve'?'pve/':'').'factory.json',['mapId'=>'factory','pois'=>[
            ['id'=>'stable','name'=>'Точка','description'=>'Описание','category'=>'quest','position'=>['x'=>1,'y'=>0,'z'=>2],'gameMode'=>$mode,'navigator'=>['layer'=>'quests','index'=>0],
                'custom'=>['keep'=>true],'outline'=>[['x'=>2,'y'=>0,'z'=>3]],'cmsWorldPositions'=>[['world_x'=>1,'world_y'=>0,'world_z'=>2,'order_index'=>0]]]]]);}
        app(WikiImporter::class)->rebuild();
        $this->actingAs(User::create(['name'=>'Редактор','email'=>'map@example.test','password'=>bcrypt('local-test'),'permissions'=>['platform.index'=>true,'wiki.content'=>true]]));
    }
    private function payload(array $fields=[]): array
    {
        $s=app(RaidMapEditor::class)->snapshot('factory','regular');
        return ['revision'=>$s['revision'],'definitionRevision'=>$s['definitionRevision'],'changes'=>[[
            'id'=>'stable','new'=>false,'name'=>'  Исправленная точка  ','description'=>"Строка 1\nСтрока 2",'category'=>'quest','lootType'=>null,
            'position'=>['x'=>0,'y'=>4,'z'=>-10],'floor'=>'upper','archived'=>false,...$fields]]];
    }
    private function saveUrl(): string { return '/admin/map-editor/points?map=factory&mode=regular'; }
    public function test_move_publishes_coordinates_and_floor_preserving_ids_and_other_mode(): void
    {
        $this->fixtures();$id=DB::table('wiki_map_points')->where('mode','regular')->value('id');$pve=ContentDataset::where('path','raid/pve/factory.json')->value('payload');
        $this->get('/admin/map-editor')->assertOk()->assertSee('Переместить следующим нажатием');
        $this->getJson('/admin/map-editor/data?map=factory&mode=regular')->assertOk()->assertHeader('Cache-Control','no-store, private');
        $patch=$this->payload();$this->postJson($this->saveUrl(),$patch)->assertOk()->assertJsonPath('saved.0','stable');
        $point=ContentDataset::where('path','raid/factory.json')->first()->content()['pois'][0];
        $this->assertSame('Исправленная точка',$point['name']);$this->assertEquals($patch['changes'][0]['position'],$point['position']);
        $this->assertSame('upper',$point['floorOverride']);$this->assertTrue($point['editorPosition']);$this->assertSame(['keep'=>true],$point['custom']);
        $this->assertEquals(['x'=>1,'y'=>4,'z'=>-9],$point['outline'][0]);
        $this->assertSame($id,DB::table('wiki_map_points')->where('mode','regular')->value('id'));
        $this->assertEquals(-10,DB::table('wiki_point_world_positions')->where('point_id',$id)->where('archived',0)->value('world_z'));
        $this->assertSame($pve,ContentDataset::where('path','raid/pve/factory.json')->value('payload'));
        $this->postJson($this->saveUrl(),$patch)->assertConflict();
        $this->getJson('/api/content/data/raid/factory.json')->assertOk()->assertJsonPath('pois.0.floorOverride','upper');
    }
    public function test_create_archive_restore_and_retry_do_not_duplicate(): void
    {
        $this->fixtures();$patch=$this->payload(['id'=>'editor-'.str_repeat('a',32),'new'=>true]);
        $this->postJson($this->saveUrl(),$patch)->assertOk();$this->postJson($this->saveUrl(),$patch)->assertConflict();
        $this->assertSame(3,DB::table('wiki_map_points')->count());
        foreach([true,false] as $archived){$this->postJson($this->saveUrl(),$this->payload(['id'=>$patch['changes'][0]['id'],'archived'=>$archived]))->assertOk();}
        $this->assertSame(3,DB::table('wiki_map_points')->count());
        $this->assertSame(['create_raid_point','archive_raid_point','restore_raid_point'],DB::table('wiki_document_map_audit')->where('result','success')->orderBy('id')->pluck('entity_type')->all());
    }
    public function test_invalid_payloads_and_foreign_ids_never_partially_write(): void
    {
        $this->fixtures();$ref=ContentDataset::where('path','raid/factory.json')->value('payload');
        foreach([['position'=>['x'=>[],'y'=>0,'z'=>0]],['position'=>['x'=>'1e999','y'=>0,'z'=>0]],['name'=>[]],['name'=>'<img src=x onerror=alert(1)>'],
            ['category'=>'other'],['category'=>'loot'],['floor'=>'other-map-floor'],['id'=>'other-map-point'],['name'=>str_repeat('я',256)],['position'=>['x'=>0,'y'=>0,'z'=>0,'owner'=>2]]] as $fields){
            $this->postJson($this->saveUrl(),$this->payload($fields))->assertUnprocessable();$this->assertSame($ref,ContentDataset::where('path','raid/factory.json')->value('payload'));
        }
        $patch=$this->payload();$patch['changes'][]=[...$patch['changes'][0],'id'=>'missing'];$this->postJson($this->saveUrl(),$patch)->assertUnprocessable();
        $this->assertSame($ref,ContentDataset::where('path','raid/factory.json')->value('payload'));
        $patch=$this->payload();$patch['changes']='wrong';$this->postJson($this->saveUrl(),$patch)->assertUnprocessable();
        $this->getJson('/admin/map-editor/data?map[]=factory&mode=regular')->assertUnprocessable();
    }
    public function test_permissions_and_csrf_are_required(): void
    {
        $this->fixtures();$patch=$this->payload();$user=auth()->user();$user->permissions=['platform.index'=>true];$user->save();
        $this->getJson('/admin/map-editor/data')->assertForbidden();$this->postJson($this->saveUrl(),$patch)->assertForbidden();
        $user->permissions=['platform.index'=>true,'wiki.content'=>true];$user->save();
        $this->app['env']='production';$this->postJson($this->saveUrl(),$patch)->assertStatus(419);
    }

    public function test_audit_tracks_success_and_rejected_batches_without_form_values(): void
    {
        $this->fixtures();$patch=$this->payload();
        $this->postJson($this->saveUrl(),$patch)->assertOk();
        $this->postJson($this->saveUrl(),$patch)->assertConflict();
        $invalid=$this->payload();$invalid['changes'][]=[...$invalid['changes'][0],'id'=>'missing'];
        $this->postJson($this->saveUrl(),$invalid)->assertUnprocessable();
        $rows=DB::table('wiki_document_map_audit')->orderBy('id')->get();
        $this->assertSame(['success','denied','denied'],$rows->pluck('result')->all());
        $this->assertEquals(DB::table('wiki_map_points')->where('mode','regular')->value('id'),$rows[0]->point_id);
        $this->assertSame('update_raid_point',$rows[0]->entity_type);
        $this->assertSame($patch['revision'],$rows[0]->revision_before);
        $this->assertSame(app(RaidMapEditor::class)->snapshot('factory','regular')['revision'],$rows[0]->revision_after);
        $previous=str_repeat('0',64);
        foreach($rows as $row){
            $this->assertSame($previous,$row->previous_hash);$values=(array)$row;$previous=$values['signature'];unset($values['id'],$values['signature']);
            $this->assertSame($previous,hash_hmac('sha256',implode("\n",array_map(fn($value)=>(string)$value,array_values($values))),config('app.key')));
        }
        $this->assertStringNotContainsString('Исправленная',json_encode($rows,JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('map@example.test',json_encode($rows));
        $this->getJson('/admin/document-maps/audit')->assertForbidden();
        $this->actingAs(User::create(['name'=>'Аудитор','email'=>'raid-auditor@example.test','password'=>bcrypt('local-test'),'permissions'=>['platform.index'=>true,'wiki.audit.read'=>true]]));
        $this->getJson('/admin/document-maps/audit')->assertOk()->assertJsonCount(3,'events');
        $this->getJson('/admin/map-editor/data')->assertForbidden();
    }

    public function test_catalog_conflict_empty_values_and_item_links_are_checked(): void
    {
        $this->fixtures();$patch=$this->payload();$catalog=ContentDataset::where('path','raid/maps.json')->first();
        $data=$catalog->content();$data[0]['name']='Новая схема';$catalog->setContent($data);$catalog->save();
        $this->postJson($this->saveUrl(),$patch)->assertConflict();
        $this->postJson($this->saveUrl(),$this->payload(['name'=>'  ']))->assertUnprocessable();
        $this->postJson($this->saveUrl(),$this->payload(['description'=>'']))->assertOk();
        foreach([null,'unknown-box'] as $type){$this->postJson($this->saveUrl(),$this->payload(['id'=>'editor-'.str_repeat('c',32),'new'=>true,'category'=>'loot','lootType'=>$type]))->assertUnprocessable();}
        $this->dataset('raid/item-names.json',['item-test'=>'Предмет 100%_!','item-other'=>'Предмет другой']);
        app(WikiImporter::class)->dataset(ContentDataset::where('path','raid/item-names.json')->first());
        $this->getJson('/admin/map-editor/items?search='.rawurlencode('100%_!'))->assertOk()->assertJsonCount(1,'items')->assertJsonPath('items.0.id','item-test');
        $this->getJson('/admin/map-editor/items?search[]=x')->assertUnprocessable();
        $this->getJson('/admin/map-editor/items?search='.rawurlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0,'items');
        $point=['id'=>'editor-'.str_repeat('b',32),'new'=>true,'category'=>'loose-loot','itemId'=>'missing'];
        $this->postJson($this->saveUrl(),$this->payload($point))->assertUnprocessable();
        $this->postJson($this->saveUrl(),$this->payload([...$point,'itemId'=>'item-test']))->assertOk();
        $data=app(RaidMapEditor::class)->snapshot('factory','regular')['data'];
        $this->assertSame(['item-test'],$data['pois'][1]['itemIds']);
        $id=DB::table('wiki_map_points')->where('external_id',$point['id'])->value('id');
        $this->assertNotNull($id);
        $this->postJson($this->saveUrl(),$this->payload(['new'=>false,'id'=>$point['id'],'category'=>'loose-loot']))->assertOk();
        $this->assertSame($id,DB::table('wiki_map_points')->where('external_id',$point['id'])->value('id'));
        $this->assertSame(['item-test'],app(RaidMapEditor::class)->snapshot('factory','regular')['data']['pois'][1]['itemIds']);
    }
}
