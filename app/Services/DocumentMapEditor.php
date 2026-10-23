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

    public function snapshot(string $mapId,string $mode,bool $includeArchived=false): array
    {
        [$map,$sqlMap,$locations]=$this->context($mapId);
        $points=DB::table('wiki_map_points')->whereIn('location_id',$locations)
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
        $byExternal=$points->keyBy('external_id');$placed=$exits->keyBy('point_id');$seen=[];
        $raid=$datasets->get($paths[3])?->content()??[];
        if(isset($raid['pois']))foreach($raid['pois'] as &$poi){$point=$byExternal[$poi['id']??'']??null;if($point&&($point->archived||!$point->active))$poi['cmsArchived']=false;}unset($poi);
        $legacy=app(DocumentMapOverlay::class)->build($map,[],$definition,$raid,[])['exits'];
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
        foreach($rows as &$row){$point=$points[$row['pointId']];$row['description']=$point->description;
            $row['archived']=(bool)($point->archived||!$point->active);$row['documentId']=$documents->firstWhere('point_id',$point->id)?->document_id;
            $row['clientId']=str_starts_with($point->external_id,'document-editor-')?substr($point->external_id,16):null;}unset($row);
        if(!$includeArchived)$rows=array_values(array_filter($rows,fn($row)=>!$row['archived']));
        return ['map'=>$map,'mode'=>$mode,'version'=>$version,'points'=>$rows,
            'capabilities'=>['archivePoint'=>true,'batchPoints'=>true,'editPointDetails'=>true,'restorePoint'=>true,'maxBatch'=>100],
            'floors'=>array_keys($map['documentPanels']??['floor-1'=>[]]),
            'documents'=>DB::table('wiki_documents')->where('archived',0)->orderBy('name')->get(['id','name'])->all()];
    }

    public function save(string $mapId,string $mode,array $input,string $actor): array
    {
        Validator::make($input,['version'=>['bail','required','string','size:64']])->validate();
        $version=$input['version'];unset($input['version']);
        try{$result=$this->saveBatch($mapId,$mode,['version'=>$version,'changes'=>[$input]],$actor);}
        catch(ValidationException $failure){$errors=[];foreach($failure->errors() as $field=>$messages)$errors[preg_replace('/^changes\.0\./','',$field)]=$messages;throw ValidationException::withMessages($errors);}
        $result['snapshot']['points']=array_values(array_filter($result['snapshot']['points'],fn($point)=>!$point['archived']));
        return ['pointId'=>$result['applied'][0]['pointId'],'snapshot'=>$result['snapshot']];
    }

    /** Проверяет весь пакет до записи и публикует его одной транзакцией под общей CAS-версией. */
    public function saveBatch(string $mapId,string $mode,array $input,string $actor): array
    {
        $values=Validator::make($input,['version'=>['bail','required','string','size:64'],
            'changes'=>['bail','required','array','list','min:1','max:100'],
            'changes.*'=>['bail','required','array:clientId,kind,pointId,positionId,floor,x,y,title,description,documentId,archived'],
        ])->validate();
        if(array_diff(array_keys($input),['version','changes']))throw ValidationException::withMessages(['form'=>'Недопустимые поля формы.']);
        $changes=[];$keys=[];$clients=[];$shared=[];
        foreach($values['changes'] as $index=>$change){
            foreach(['title','description'] as $field)if(array_key_exists($field,$change))$change[$field]=AdminInput::trim($change[$field]);
            $archived=filter_var($change['archived']??false,FILTER_VALIDATE_BOOLEAN);
            try{$change=Validator::make($change,[
                'clientId'=>['bail','sometimes','string','max:100','regex:/^[A-Za-z0-9:_-]+$/D'],
                'kind'=>['bail','required','string',Rule::in(['document','exit'])],
                'pointId'=>['bail','nullable','integer','min:1'],
                'positionId'=>['bail','nullable',Rule::prohibitedIf(($change['kind']??null)==='exit'),'integer','min:1'],
                'archived'=>['bail','sometimes','boolean'],
                'floor'=>['bail',Rule::requiredIf(!$archived),'nullable','string','max:40'],
                'x'=>['bail',Rule::requiredIf(!$archived),'nullable','numeric',AdminInput::finite(),'between:0,100'],
                'y'=>['bail',Rule::requiredIf(!$archived),'nullable','numeric',AdminInput::finite(),'between:0,100'],
                'title'=>['bail','sometimes','required',...AdminInput::rules('title',255)],
                'description'=>['bail','sometimes','nullable',...AdminInput::rules('description',10000)],
                'documentId'=>['bail','nullable','integer','min:1'],
            ],AdminInput::messages())->validate();
                if(empty($change['pointId'])&&((!isset($change['title'])||$change['title']==='')||$archived))throw ValidationException::withMessages(['title'=>'Укажите название новой точки и её положение.']);
            }catch(ValidationException $failure){$errors=[];foreach($failure->errors() as $field=>$messages)$errors['changes.'.$index.'.'.$field]=$messages;throw ValidationException::withMessages($errors);}
            if(isset($change['archived']))$change['archived']=(bool)$change['archived'];
            foreach(['pointId','positionId','documentId'] as $field)if(isset($change[$field]))$change[$field]=(int)$change[$field];
            if(array_key_exists('description',$change))$change['description']??='';
            if(!empty($change['pointId'])){
                $key=$change['kind'].':'.$change['pointId'].':'.($change['positionId']??0);
                if(isset($keys[$key]))throw ValidationException::withMessages(['changes.'.$index.'.pointId'=>'Позиция повторяется в пакете.']);$keys[$key]=true;
                foreach(['floor','title','description','archived'] as $field)if(array_key_exists($field,$change)){
                    $key=$change['kind'].':'.$change['pointId'].':'.$field;
                    if(array_key_exists($key,$shared)&&$shared[$key]!==$change[$field])throw ValidationException::withMessages(['changes.'.$index.'.'.$field=>'Общее поле альтернативных позиций должно совпадать.']);$shared[$key]=$change[$field];
                }
            }
            if(isset($change['clientId'])){if(isset($clients[$change['clientId']]))throw ValidationException::withMessages(['changes.'.$index.'.clientId'=>'Черновик повторяется в пакете.']);$clients[$change['clientId']]=true;}
            $changes[]=$change;
        }
        return app(WikiSync::class)->run(fn()=>DB::transaction(function() use($mapId,$mode,$values,$changes,$actor){
            $snapshot=$this->snapshot($mapId,$mode,true);
            abort_unless(hash_equals($snapshot['version'],$values['version']),409,'Карта уже изменилась. Все правки остались в форме. Загрузите свежие данные перед повторным сохранением.');
            [$map,$sqlMap]=$this->context($mapId);
            foreach($changes as $index=>$change){
                if(empty($change['pointId'])&&isset($change['clientId']))abort_if(DB::table('wiki_map_points')->where('external_id','document-editor-'.$change['clientId'])->exists(),409,'Этот черновик уже сохранён. Загрузите свежие данные, чтобы не создавать дубликат.');
                if(!($change['archived']??false)&&!in_array($change['floor'],$snapshot['floors'],true))throw ValidationException::withMessages(['changes.'.$index.'.floor'=>'Выберите этаж этой карты.']);
                if(!empty($change['pointId'])){
                    $point=collect($snapshot['points'])->first(fn($row)=>$row['pointId']===$change['pointId']&&$row['kind']===$change['kind']&&$row['positionId']===($change['positionId']??null));
                    if(!$point)throw ValidationException::withMessages(['changes.'.$index.'.pointId'=>'Точка не относится к выбранной карте и режиму.']);
                }elseif($change['kind']==='document'&&!collect($snapshot['documents'])->contains('id',$change['documentId']??null))throw ValidationException::withMessages(['changes.'.$index.'.documentId'=>'Выберите документ по названию.']);
            }
            $applied=[];
            foreach($changes as $change)$applied[]=['clientId'=>$change['clientId']??null,'pointId'=>$this->applyChange($mapId,$mode,$map,$sqlMap,$snapshot,$change),'positionId'=>$change['positionId']??null];
            $after=$this->snapshot($mapId,$mode,true);
            foreach($applied as $index=>&$result){$change=$changes[$index];
                if(!$result['positionId']&&$change['kind']==='document')$result['positionId']=collect($after['points'])->firstWhere('pointId',$result['pointId'])['positionId']??null;
                $before=collect($snapshot['points'])->firstWhere('pointId',$result['pointId']);
                $restored=$before&&$before['archived']&&array_key_exists('archived',$change)&&!$change['archived'];
                $action=!$before?'create_':(($change['archived']??false)?'archive_':($restored?'restore_':'move_'));
                $fields=!$before?'point,title,document_id,floor,x_percent,y_percent':(($change['archived']??false)?'active,archived':'floor,x_percent,y_percent');
                if($restored)$fields.=',active,archived';
                foreach(['title','description'] as $field)if(array_key_exists($field,$change)&&!in_array($field,explode(',',$fields),true))$fields.=','.$field;
                app(DocumentMapAudit::class)->append($actor,$result['pointId'],'success',$values['version'],$after['version'],$action.$change['kind'],$fields);
            }unset($result);
            return ['snapshot'=>$after,'applied'=>$applied];
        }));
    }

    private function applyChange(string $mapId,string $mode,array $map,object $sqlMap,array $snapshot,array $values): int
    {

        $editor=app(WikiEditor::class);$pointId=$values['pointId']??null;$kind=$values['kind'];
        $selected=null;
        if($pointId){
            $selected=collect($snapshot['points'])->first(fn($row)=>$row['pointId']===$pointId&&$row['kind']===$kind
                &&($kind==='exit'||$row['positionId']===($values['positionId']??null)));
            abort_unless($selected,422,'Точка не относится к выбранной карте и режиму.');
            $patch=[];
            foreach(['title','description'] as $field)if(array_key_exists($field,$values)&&$values[$field]!==($field==='title'?$selected['name']:$selected[$field]))$patch[$field]=$values[$field];
            if(array_key_exists('archived',$values)&&$values['archived']!==$selected['archived']){$patch['archived']=$values['archived']?1:0;$patch['active']=$values['archived']?0:1;}
            if($patch)$this->editEntity('map_points',$pointId,$patch);
            if($values['archived']??false)return $pointId;
        }else{
            if($kind==='document'&&!collect($snapshot['documents'])->contains('id',$values['documentId']??null)){
                throw ValidationException::withMessages(['documentId'=>'Выберите документ по названию.']);
            }
            $pointId=$editor->save('map_points',null,['location_id'=>$sqlMap->location_id,'mode'=>$kind==='document'?'all':$mode,
                'title'=>$values['title'],'description'=>$values['description']??'','active'=>1,'archived'=>0]);
            if(isset($values['clientId']))DB::table('wiki_map_points')->where('id',$pointId)->update(['external_id'=>'document-editor-'.$values['clientId']]);
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
                $this->editEntity('point_positions',$other->id,['layer_id'=>$layerId,
                    'x_percent'=>$panel['x']+($other->x_percent-$previous['x'])/$previous['width']*$panel['width'],
                    'y_percent'=>$panel['y']+($other->y_percent-$previous['y'])/$previous['height']*$panel['height']]);
            }
            if(!empty($selected['positionId']))$this->editEntity('point_positions',$selected['positionId'],$patch);
            else $editor->save('point_positions',null,$patch);
        }
        return $pointId;
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

    private function editEntity(string $type,int $id,array $patch): void
    {
        $schema=app(WikiSchema::class);$row=$schema->record($type,$id)->firstOrFail();$input=['revision'=>$row->revision];
        foreach($schema->fields($type) as $name=>$column)$input[$name]=$row->$name;
        app(WikiEditor::class)->save($type,$id,[...$input,...$patch]);
    }
}
