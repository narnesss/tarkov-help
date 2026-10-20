<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\ContentDataset;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\{Rule, ValidationException};

/** Редактирует позиции схем, сохраняя идентичность точек и игровые координаты выходов. */
final class DocumentMapEditor
{
    public function maps(): array
    {
        return array_values(array_filter(ContentDataset::where('path','maps.json')->firstOrFail()->content(),
            fn($map)=>empty($map['cmsArchived'])));
    }

    private function context(string $mapId): array
    {
        $map=collect($this->maps())->firstWhere('id',$mapId); abort_unless($map,404);
        $sqlMap=DB::table('wiki_maps as m')->join('wiki_locations as l','l.id','=','m.location_id')
            ->where('m.slug','documents')->where('m.archived',0)->where('l.slug',$mapId)->select('m.*')->first();
        abort_unless($sqlMap,404);
        $locations=DB::table('wiki_locations')->whereIn('slug',[$mapId,DocumentMapOverlay::ALIASES[$mapId]??$mapId])->pluck('id')->all();
        return [$map,$sqlMap,$locations];
    }

    public function snapshot(string $mapId,string $mode): array
    {
        [$map,$sqlMap,$locations]=$this->context($mapId);
        $points=DB::table('wiki_map_points')->whereIn('location_id',$locations)->where('archived',0)->where('active',1)
            ->whereIn('mode',[$mode,'all'])->where(function($query){
                $query->whereExists(fn($q)=>$q->selectRaw('1')->from('wiki_point_documents as d')->whereColumn('d.point_id','wiki_map_points.id')->where('d.archived',0))
                    ->orWhereExists(fn($q)=>$q->selectRaw('1')->from('wiki_point_category_links as c')->whereColumn('c.point_id','wiki_map_points.id')->where('c.archived',0)->whereIn('c.category',['extract-pmc','extract-shared']));
            })->orderBy('id')->get()->keyBy('id');
        $positions=DB::table('wiki_point_positions as v')->join('wiki_map_layers as f','f.id','=','v.layer_id')
            ->where('v.map_id',$sqlMap->id)->where('v.archived',0)->orderBy('v.point_id')->orderBy('v.order_index')
            ->get(['v.*','f.external_id as floor']);
        $documents=DB::table('wiki_point_documents')->whereIn('point_id',$points->keys())->where('archived',0)->orderBy('point_id')->orderBy('document_id')->get();
        $exits=DB::table('wiki_document_exit_positions')->where('map_id',$sqlMap->id)->orderBy('point_id')->get();
        $floors=DB::table('wiki_map_layers')->where('map_id',$sqlMap->id)->pluck('external_id','id');
        $categories=DB::table('wiki_point_category_links')->whereIn('point_id',$points->keys())->where('archived',0)
            ->whereIn('category',['extract-pmc','extract-shared'])->pluck('point_id')->all();
        $rows=[];
        foreach($positions as $position){
            $point=$points[$position->point_id]??null;
            if(!$point||!$documents->contains('point_id',$point->id))continue;
            $panel=$map['documentPanels'][$position->floor]??null;
            $rows[]=['kind'=>'document','pointId'=>$point->id,'positionId'=>$position->id,'name'=>$point->title,
                'externalId'=>$point->external_id,'floor'=>$position->floor,'order'=>(int)$position->order_index,
                'x'=>$panel?($position->x_percent-$panel['x'])/$panel['width']*100:(float)$position->x_percent,
                'y'=>$panel?($position->y_percent-$panel['y'])/$panel['height']*100:(float)$position->y_percent];
        }
        $raidId=DocumentMapOverlay::ALIASES[$mapId]??$mapId;
        $paths=['maps.json','spawns.json','raid/maps.json','raid/'.($mode==='pve'?'pve/':'').$raidId.'.json'];
        $datasets=ContentDataset::whereIn('path',$paths)->orderBy('path')->get()->keyBy('path');
        $definition=collect($datasets->get('raid/maps.json')?->content()??[])->firstWhere('id',$raidId)??[];
        $legacy=app(DocumentMapOverlay::class)->build($map,[],$definition,$datasets->get($paths[3])?->content()??[],[])['exits'];
        $byExternal=$points->keyBy('external_id');$placed=$exits->keyBy('point_id');$seen=[];
        foreach($legacy as $exit){
            $point=$byExternal[$exit['id']]??null; if(!$point)continue;
            $seen[$point->id]=true;$position=$placed[$point->id]??null;
            $floor=$position?$floors[$position->layer_id]:$exit['floor'];
            $rows[]=['kind'=>'exit','pointId'=>$point->id,'positionId'=>null,'externalId'=>$point->external_id,'name'=>$point->title,
                'floor'=>$floor,'x'=>$position?(float)$position->x_percent:$exit['x'],'y'=>$position?(float)$position->y_percent:$exit['y'],
                'independent'=>(bool)$position,'order'=>0];
        }
        foreach($points as $point){
            if($documents->contains('point_id',$point->id)){
                if(!$positions->contains('point_id',$point->id))$rows[]=['kind'=>'document','pointId'=>$point->id,'positionId'=>null,
                    'externalId'=>$point->external_id,'name'=>$point->title,'floor'=>null,'x'=>null,'y'=>null,'order'=>0];
                continue;
            }
            if(isset($seen[$point->id])||!in_array($point->id,$categories,true))continue;
            $position=$placed[$point->id]??null;
            $rows[]=['kind'=>'exit','pointId'=>$point->id,'positionId'=>null,'externalId'=>$point->external_id,'name'=>$point->title,
                'floor'=>$position?$floors[$position->layer_id]:null,
                'x'=>$position?(float)$position->x_percent:null,'y'=>$position?(float)$position->y_percent:null,'independent'=>(bool)$position,'order'=>0];
        }
        $version=hash('sha256',serialize([$map,$points->all(),$positions->all(),$documents->all(),$exits->all(),
            $datasets->map(fn($dataset)=>$dataset->getRawOriginal('payload'))->all()]));
        return ['map'=>$map,'mode'=>$mode,'version'=>$version,'points'=>$rows,'capabilities'=>['archivePoint'=>true],
            'floors'=>array_keys($map['documentPanels']??['floor-1'=>[]]),
            'documents'=>DB::table('wiki_documents')->where('archived',0)->orderBy('name')->get(['id','name'])->all()];
    }

    public function save(string $mapId,string $mode,array $input,string $actor): array
    {
        $input['title']=AdminInput::trim($input['title']??null);
        $values=Validator::make($input,[
            'version'=>['required','string','size:64'],'kind'=>['required',Rule::in(['document','exit'])],
            'pointId'=>['bail','nullable','integer','min:1'],'positionId'=>['bail','nullable',Rule::prohibitedIf(($input['kind']??null)==='exit'),'integer','min:1'],
            'floor'=>['required','string','max:40'],'x'=>['bail','required','numeric',AdminInput::finite(),'between:0,100'],
            'y'=>['bail','required','numeric',AdminInput::finite(),'between:0,100'],
            'title'=>['bail','nullable','required_without:pointId',...AdminInput::rules('title',255)],
            'documentId'=>['bail','nullable','integer','min:1'],
        ],AdminInput::messages())->validate();
        if(array_diff(array_keys($input),array_keys($values)))throw ValidationException::withMessages(['form'=>'Недопустимые поля формы.']);
        return app(WikiSync::class)->run(fn()=>DB::transaction(function() use($mapId,$mode,$values,$actor){
            $snapshot=$this->snapshot($mapId,$mode);
            abort_unless(hash_equals($snapshot['version'],$values['version']),409,'Карта уже изменилась. Правка осталась в форме. Обновите карту перед повторным сохранением.');
            [$map,$sqlMap]=$this->context($mapId);
            if(!in_array($values['floor'],$snapshot['floors'],true))throw ValidationException::withMessages(['floor'=>'Выберите этаж этой карты.']);
            $editor=app(WikiEditor::class);$pointId=$values['pointId']??null;$kind=$values['kind'];
            $selected=null;
            if($pointId){
                $selected=collect($snapshot['points'])->first(fn($row)=>$row['pointId']===$pointId&&$row['kind']===$kind
                    &&($kind==='exit'||$row['positionId']===($values['positionId']??null)));
                abort_unless($selected,422,'Точка не относится к выбранной карте и режиму.');
            }else{
                if($kind==='document'&&!collect($snapshot['documents'])->contains('id',$values['documentId']??null)){
                    throw ValidationException::withMessages(['documentId'=>'Выберите документ по названию.']);
                }
                $pointId=$editor->save('map_points',null,['location_id'=>$sqlMap->location_id,'mode'=>$kind==='document'?'all':$mode,
                    'title'=>$values['title'],'description'=>'','active'=>1,'archived'=>0]);
                if($kind==='document')$editor->save('point_documents',null,['point_id'=>$pointId,'document_id'=>$values['documentId'],'archived'=>0]);
                else{
                    $category=app(WikiSchema::class)->query('point_categories')->where('code','extract-pmc')->value('rowid');
                    $editor->save('point_category_links',null,['point_id'=>$pointId,'category'=>$category,'archived'=>0]);
                    $editor->save('extractions',null,['point_id'=>$pointId,'type'=>'pmc','requirements'=>'','archived'=>0]);
                }
            }
            $layer=DB::table('wiki_map_layers')->where(['map_id'=>$sqlMap->id,'external_id'=>$values['floor']])->first();
            $layerId=$layer?->id??app(WikiStore::class)->put('map_layers',['map_id'=>$sqlMap->id,'external_id'=>$values['floor']],['name'=>$values['floor']]);
            if($kind==='exit'){
                $old=DB::table('wiki_document_exit_positions')->where(['point_id'=>$pointId,'map_id'=>$sqlMap->id])->first();
                $attributes=['layer_id'=>$layerId,'x_percent'=>(float)$values['x'],'y_percent'=>(float)$values['y'],'revision'=>($old?->revision??-1)+1];
                DB::table('wiki_document_exit_positions')->updateOrInsert(['point_id'=>$pointId,'map_id'=>$sqlMap->id],$attributes);
            }else{
                $panel=$map['documentPanels'][$values['floor']]??['x'=>0,'y'=>0,'width'=>100,'height'=>100];
                $patch=['point_id'=>$pointId,'map_id'=>$sqlMap->id,'layer_id'=>$layerId,'order_index'=>$selected['order']??0,
                    'x_percent'=>$panel['x']+$values['x']/100*$panel['width'],'y_percent'=>$panel['y']+$values['y']/100*$panel['height'],'archived'=>0];
                // Альтернативные позиции сохраняют своё место при переносе документа на другой этаж.
                foreach(DB::table('wiki_point_positions as v')->join('wiki_map_layers as f','f.id','=','v.layer_id')
                    ->where('v.point_id',$pointId)->where('v.map_id',$sqlMap->id)->where('v.archived',0)->get(['v.*','f.external_id as floor']) as $other){
                    if($other->id===($selected['positionId']??null)||$other->floor===$values['floor'])continue;
                    $previous=$map['documentPanels'][$other->floor]??['x'=>0,'y'=>0,'width'=>100,'height'=>100];
                    $this->editPosition($other->id,['layer_id'=>$layerId,
                        'x_percent'=>$panel['x']+($other->x_percent-$previous['x'])/$previous['width']*$panel['width'],
                        'y_percent'=>$panel['y']+($other->y_percent-$previous['y'])/$previous['height']*$panel['height']]);
                }
                if(!empty($selected['positionId']))$this->editPosition($selected['positionId'],$patch);
                else $editor->save('point_positions',null,$patch);
            }
            $after=$this->snapshot($mapId,$mode);
            app(DocumentMapAudit::class)->append($actor,$pointId,'success',$values['version'],$after['version'],($selected?'move_':'create_').$kind);
            return ['pointId'=>$pointId,'snapshot'=>$after];
        }));
    }

    /** Архивирует всю точку, сохраняя её ID, позиции и связи для восстановления. */
    public function remove(string $mapId,string $mode,array $input,string $actor): array
    {
        $values=Validator::make($input,[
            'version'=>['bail','required','string','size:64'],
            'kind'=>['bail','required','string',Rule::in(['document','exit'])],
            'pointId'=>['bail','required','integer','min:1'],
        ])->validate();
        if(array_diff(array_keys($input),array_keys($values)))throw ValidationException::withMessages(['form'=>'Недопустимые поля формы.']);
        return app(WikiSync::class)->run(fn()=>DB::transaction(function() use($mapId,$mode,$values,$actor){
            $snapshot=$this->snapshot($mapId,$mode);
            abort_unless(hash_equals($snapshot['version'],$values['version']),409,'Карта уже изменилась. Точка не удалена. Загрузите свежие данные перед повторным удалением.');
            $pointId=(int)$values['pointId'];
            $selected=collect($snapshot['points'])->first(fn($row)=>$row['pointId']===$pointId&&$row['kind']===$values['kind']);
            abort_unless($selected,422,'Точка не относится к выбранной карте и режиму.');
            $before=DB::table('wiki_map_points')->where('id',$pointId)->firstOrFail();
            $updated=DB::table('wiki_map_points')->where('id',$pointId)->where('revision',$before->revision)
                ->where('archived',0)->where('active',1)->increment('revision',1,['active'=>0,'archived'=>1]);
            abort_unless($updated===1,409,'Точка уже изменилась. Обновите карту перед повторным удалением.');
            app(WikiPublisher::class)->publish('map_points',$pointId,(array)$before);
            $after=$this->snapshot($mapId,$mode);
            app(DocumentMapAudit::class)->append($actor,$pointId,'success',$values['version'],$after['version'],
                'archive_'.$values['kind'],'active,archived');
            return ['pointId'=>$pointId,'snapshot'=>$after];
        }));
    }

    private function editPosition(int $id,array $patch): void
    {
        $schema=app(WikiSchema::class);$row=$schema->record('point_positions',$id)->firstOrFail();$input=['revision'=>$row->revision];
        foreach($schema->fields('point_positions') as $name=>$column)$input[$name]=$row->$name;
        app(WikiEditor::class)->save('point_positions',$id,[...$input,...$patch]);
    }
}
