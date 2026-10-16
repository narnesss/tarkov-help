<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{ContentDataset,Quest};
use Illuminate\Support\Facades\DB;

/** Публикует изменения SQLite в совместимые файлы, сохраняя остальные поля. */
final class WikiPublisher
{
    private array $datasets=[];
    public function __construct(private WikiSchema $schema,private WikiStore $store) {}
    private function row(string $type,int $id): array
    { return $this->schema->record($type,$id)->first()?->getAttributes()??[]; }
    private function rows(string $type,array $where): array
    { return $this->schema->query($type)->where($where)->where('archived',0)->get()->map(fn($r)=>$r->getAttributes())->all(); }
    public function publish(string $type,int $id,array $before=[]): void
    {
        $row=$this->row($type,$id);$questIds=[];$pointIds=[];
        $entity=['items'=>'item','locations'=>'location','maps'=>'map','map_layers'=>'layer','documents'=>'document','map_points'=>'point',
            'traders'=>'trader','skills'=>'skill','stations'=>'station','achievements'=>'achievement','crafts'=>'craft','offers'=>'offer'][$type]??null;
        if($entity!==null){$this->publishEntity($type,$id,$row,$entity);}
        if($type==='quests'){$this->quest($id,'main');}
        elseif($type==='objectives'){$this->quest($row['quest_id'],'objective',$id);}
        elseif($type==='objective_groups'){$objective=$this->row('objectives',$row['objective_id']);$this->quest($objective['quest_id'],'conditions',$row['objective_id']);}
        elseif($type==='objective_items'){$group=$this->row('objective_groups',$row['group_id']);$objective=$this->row('objectives',$group['objective_id']);$this->quest($objective['quest_id'],'conditions',$group['objective_id']);}
        elseif(in_array($type,['objective_locations','objective_keys','objective_points'],true)){
            $objective=$this->row('objectives',$row['objective_id']);$this->quest($objective['quest_id'],$type,$row['objective_id']);
            if($type==='objective_points'){$pointIds[]=$row['point_id'];if(isset($before['point_id'])){$pointIds[]=$before['point_id'];}}
        }
        elseif(in_array($type,['prerequisite_groups','quest_prerequisites'],true)){
            $group=$type==='prerequisite_groups'?$row:$this->row('prerequisite_groups',$row['group_id']);$this->quest($group['quest_id'],'requirements');
        }
        elseif(in_array($type,['quest_trader_requirements','quest_skill_requirements','quest_station_requirements'],true)){$this->quest($row['quest_id'],$type);}
        elseif($type==='reward_sets'){$this->quest($row['quest_id'],'reward_sets',$id);}
        elseif(in_array($type,['reward_items','reward_standing','reward_skills','reward_unlocks'],true)){$set=$this->row('reward_sets',$row['reward_set_id']);$this->quest($set['quest_id'],$type,$row['reward_set_id']);}
        elseif(in_array($type,['point_positions','point_world_positions','point_documents','item_spawns','key_usages','extractions','point_category_links'],true)){
            $pointIds[]=$row['point_id'];if(isset($before['point_id'])){$pointIds[]=$before['point_id'];}
        }
        elseif($type==='keys'){$item=$this->row('items',$row['item_id']);$this->publishEntity('items',$row['item_id'],$item,'item');}
        elseif($type==='craft_ingredients'){$this->publishEntity('crafts',$row['craft_id'],$this->row('crafts',$row['craft_id']),'craft');}
        elseif($type==='media_links'){
            if(!empty($row['point_id'])){$pointIds[]=$row['point_id'];}
            if(!empty($row['quest_id'])){$this->quest($row['quest_id'],'media');}
        }
        elseif($type==='media'){
            $pointIds=DB::table('wiki_media_links')->where('media_id',$id)->whereNotNull('point_id')->pluck('point_id')->all();
        }
        if($type==='map_points'){$pointIds[]=$id;}
        if($type==='locations'){
            foreach(DB::table('wiki_maps')->where('location_id',$id)->pluck('id') as $map){$this->publishEntity('maps',$map,$this->row('maps',$map),'map');}
        }
        if($type==='traders'){$questIds=DB::table('wiki_quests')->where('trader_id',$id)->pluck('id')->all();}
        foreach(array_unique($questIds) as $quest){$this->quest($quest,'main');}
        foreach(array_unique($pointIds) as $point){$this->store->put('map_points',['id'=>$point],[]);$this->publishEntity('map_points',$point,$this->row('map_points',$point),'point');
            foreach(DB::table('wiki_objective_points')->where('point_id',$point)->where('archived',0)->pluck('objective_id') as $objective){$o=$this->row('objectives',$objective);$this->quest($o['quest_id'],'objective_points',$objective);}}
        $this->flush();
    }
    private function dataset(int|string $key): array
    {
        $cache=(string)$key;if(isset($this->datasets[$cache])){return $this->datasets[$cache];}
        $model=is_int($key)?ContentDataset::findOrFail($key):ContentDataset::firstOrNew(['path'=>$key]);
        $document=$model->exists?$model->content():[];
        return $this->datasets[$cache]=['model'=>$model,'document'=>$document];
    }
    private function write(int|string $key,string $pointer,mixed $value): void
    {
        $this->dataset($key);$target=&$this->datasets[(string)$key]['document'];
        foreach(ContentFields::parts($pointer) as $part){$target=&$target[$part];}$target=$value;
    }
    private function read(int|string $key,string $pointer): mixed
    {
        $value=$this->dataset($key)['document'];foreach(ContentFields::parts($pointer) as $part){$value=is_array($value)?($value[$part]??null):null;}return $value;
    }
    private function flush(): void
    {
        $saved=[];foreach(array_keys($this->datasets) as $key){$model=$this->datasets[$key]['model'];if($model->exists&&isset($saved[$model->id])){continue;}
            $model->setContent($this->datasets[$key]['document']);
            // После записи файла не держим распакованный каталог при повторном чтении индексатором.
            // Числовой ключ и путь могут ссылаться на один элемент; освобождаем обе ссылки сразу.
            unset($this->datasets[$key]['document']);$model->save();$saved[$model->id]=true;
            DB::table('wiki_projection_states')->updateOrInsert(['owner_type'=>'dataset','owner_id'=>$model->id],['content_ref'=>$model->getRawOriginal('payload')]);}
        $this->datasets=[];
    }
    private function publishEntity(string $type,int $id,array $row,string $entity): void
    {
        $sources=DB::table('wiki_sources')->where($entity.'_id',$id)->get();
        if($sources->isEmpty()){$this->createSource($type,$id,$row,$entity);$sources=DB::table('wiki_sources')->where($entity.'_id',$id)->get();}
        foreach($sources as $source){$old=$this->read($source->dataset_id,$source->pointer);
            $value=$this->entityData($type,$row,is_array($old)?$old:[],$source->format);
            if($source->format==='name'){$value=$row['name'];}
            $this->write($source->dataset_id,$source->pointer,$value);
        }
    }
    private function createSource(string $type,int $id,array $row,string $entity): void
    {
        $destinations=match($type){
            'items'=>[['guide/items-pve.json','/items'],['guide/items-regular.json','/items']],
            'traders'=>[['guide/quests-pve.json','/traders'],['guide/quests-regular.json','/traders']],
            'locations'=>[['guide/quests-pve.json','/maps'],['guide/quests-regular.json','/maps']],
            'documents'=>[['docs.json','']], default=>[],
        };
        if($type==='maps'){
            $location=$this->row('locations',$row['location_id']);$destinations=[['maps.json','']];
        }
        if($type==='map_points'){
            $positions=$this->rows('point_positions',['point_id'=>$id]);$world=$this->rows('point_world_positions',['point_id'=>$id]);
            if($positions!==[]&&$this->rows('point_documents',['point_id'=>$id])!==[]){$destinations=[['spawns.json','']];}
            elseif($world!==[]){$location=$this->row('locations',$row['location_id']);$mode=$row['mode']==='pve'?'pve/':'';$destinations=[['raid/'.$mode.$location['slug'].'.json','/pois']];}
        }
        foreach($destinations as [$path,$parent]){
            $entry=$this->dataset($path);$model=$entry['model'];
            // Новому набору сначала нужен ID для внешнего ключа происхождения.
            if(!$model->exists){$model->setContent($entry['document']);$model->save();}
            if($type==='map_points'&&$parent==='/pois'){$this->write($path,'/mapId',$location['slug']);}
            // Дальше используем тот же ключ кэша, чтобы не сохранять одну версию дважды.
            $section=$this->read($path,$parent);if(!is_array($section)){$section=[];}
            $pointer=$parent.'/'.count($section);
            $format=$type==='map_points'?($parent==='/pois'?'raid-point':'document-point'):($type==='maps'?'document-map':'object');
            $record=$this->entityData($type,$row,[],$format);$section[]=$record;$this->write($path,$parent,$section);
            $this->store->source($model->id,$pointer,$entity,$id,$format);
            if($type==='locations'){DB::table('wiki_location_aliases')->updateOrInsert(['scheme'=>'game','value'=>$row['external_id']??$row['slug']],['location_id'=>$id]);DB::table('wiki_location_aliases')->updateOrInsert(['scheme'=>'slug','value'=>$row['slug']],['location_id'=>$id]);}
            // Привязываем числовой ключ к тому же изменяемому документу.
            $this->datasets[(string)$model->id]=&$this->datasets[$path];
        }
    }
    private function entityData(string $type,array $row,array $old,string $format): array
    {
        $data=$old;$external=$row['external_id']??null;
        if($external!==null){$data['id']=$old['id']??$external;}
        if($type==='map_points'){return $this->pointData($row,$data,$format);}
        if($type==='crafts'){$data['station']=$this->reference('stations',$row['station_id'],$old['station']??null);$data['level']=$row['level'];$data['duration']=$row['duration_seconds'];$data['productItem']=['item'=>$this->reference('items',$row['item_id'],$old['productItem']['item']??null),'count'=>$row['amount']];
            $data['requiredItems']=array_map(fn($link)=>['item'=>$this->reference('items',$link['item_id']),'count'=>$link['amount'],'consumed'=>(bool)$link['consumed']],$this->rows('craft_ingredients',['craft_id'=>$row['id']]));}
        if($type==='offers'){$data['trader']=$this->reference('traders',$row['trader_id'],$old['trader']??null);$data['minTraderLevel']=$row['level'];$data['offeredItem']=[...($old['offeredItem']??[]),'item'=>$this->reference('items',$row['item_id'],$old['offeredItem']['item']??null)];}
        if($type==='maps'){
            $location=$this->row('locations',$row['location_id']);$data['name']=$location['name'];
            if($format==='raid-map'){$data['baseAsset']['path']=$row['image_url'];}
            else{$data['id']=$old['id']??$location['slug'];$data['file']=$row['image_url'];$data['width']=$row['width'];$data['height']=$row['height'];$data['type']=$old['type']??'raster';}
            $data['cmsArchived']=(bool)$row['archived'];return $data;
        }
        foreach(['name'=>'name','description'=>'description','icon_url'=>'iconLink','image_url'=>'imageLink','wiki_url'=>'wikiLink','weight'=>'weight','grid_width'=>'width','grid_height'=>'height'] as $from=>$to){if(array_key_exists($from,$row)){$data[$to]=$row[$from];}}
        if($type==='items'){$data['shortName']=$data['shortName']??$row['name'];$key=DB::table('wiki_keys')->where('item_id',$row['id'])->first();if($key){$data['cmsKey']=['description'=>$key->description,'maxUses'=>$key->max_uses,'archived'=>(bool)$key->archived];}}
        if($type==='locations'){$data['id']=$old['id']??$row['slug'];$data['normalizedName']=$old['normalizedName']??$row['slug'];}
        if($type==='documents'){$data['category']=$row['category'];}
        if($type==='map_layers'){$data['name']=$row['name'];if($row['image_url']){$data['asset']['path']=$row['image_url'];}}
        if(!empty($row['archived'])||isset($row['active'])&&!$row['active']){$data['cmsArchived']=true;}else{unset($data['cmsArchived']);}
        return $data;
    }
    private function pointData(array $row,array $data,string $format): array
    {
        $previousPosition=$data['position']??null;$needsReview=($data['sourceStatus']??null)==='unverified-coordinates';
        $id=$row['id'];$location=$this->row('locations',$row['location_id']);$data['id']=$data['id']??$row['external_id'];
        $data['description']=$row['description'];$data['cmsArchived']=(bool)($row['archived']||!$row['active']);
        $documents=$this->rows('point_documents',['point_id'=>$id]);$items=$this->rows('item_spawns',['point_id'=>$id]);$keys=$this->rows('key_usages',['point_id'=>$id]);
        $data['itemIds']=array_map(fn($r)=>$this->external('items',$r['item_id']),$items);
        $data['keyIds']=array_map(fn($r)=>$this->external('items',$r['key_item_id']),$keys);
        $data['cmsItemSpawns']=array_map(fn($r)=>['item'=>$this->external('items',$r['item_id']),'amount'=>$r['amount'],'spawn_chance'=>$r['spawn_chance'],'notes'=>$r['notes']],$items);
        $data['cmsKeyUsages']=array_map(fn($r)=>['item'=>$this->external('items',$r['key_item_id']),'usage_type'=>$r['usage_type'],'notes'=>$r['notes']],$keys);
        $data['cmsPositions']=[];
        foreach($this->rows('point_positions',['point_id'=>$id]) as $position){$map=$this->row('maps',$position['map_id']);$version=$this->row('versions',$map['version_id']);
            $data['cmsPositions'][]=['map'=>array_intersect_key($map,array_flip(['slug','name','image_url','width','height'])),
                'version'=>array_intersect_key($version,array_flip(['code','patch','valid_from'])),
                'layer'=>empty($position['layer_id'])?null:array_intersect_key($this->row('map_layers',$position['layer_id']),array_flip(['external_id','name','image_url','order_index'])),
                'x_percent'=>$position['x_percent'],'y_percent'=>$position['y_percent'],'order_index'=>$position['order_index']];}
        $data['cmsWorldPositions']=array_map(fn($r)=>array_intersect_key($r,array_flip(['world_x','world_y','world_z','order_index'])),$this->rows('point_world_positions',['point_id'=>$id]));
        $data['cmsMedia']=[];foreach($this->rows('media_links',['point_id'=>$id]) as $link){$data['cmsMedia'][]=[...array_intersect_key($this->row('media',$link['media_id']),array_flip(['url','caption','source_url','author'])),'order_index'=>$link['order_index']];}
        $data['cmsDocuments']=array_map(fn($r)=>$this->external('documents',$r['document_id']),$documents);
        $data['cmsCategories']=array_column($this->rows('point_category_links',['point_id'=>$id]),'category');
        $data['cmsQuestLinks']=[];
        foreach($this->rows('objective_points',['point_id'=>$id]) as $link){$objective=$this->row('objectives',$link['objective_id']);$quest=$this->row('quests',$objective['quest_id']);$data['cmsQuestLinks'][]=['questId'=>$quest['external_id'],'objectiveId'=>$objective['external_id'],'mode'=>$quest['mode']];}
        if($format==='document-point'){
            $data['caption']=$row['title'];$data['map']=$location['slug'];$data['mapName']=$location['name'];
            if($documents!==[]){$doc=$this->row('documents',$documents[0]['document_id']);$data['doc']=$doc['external_id'];$data['docName']=$doc['name'];}else{unset($data['doc'],$data['docName']);$data['cmsArchived']=true;}
            $positions=$this->rows('point_positions',['point_id'=>$id]);usort($positions,fn($a,$b)=>$a['order_index']<=>$b['order_index']);unset($data['floor']);
            foreach(array_slice($positions,0,2) as $i=>$position){$suffix=$i===0?'':'2';$data['x'.$suffix]=$position['x_percent'];$data['y'.$suffix]=$position['y_percent'];if($i===0&&!empty($position['layer_id'])){$data['floor']=$this->row('map_layers',$position['layer_id'])['external_id'];}}
            if(count($positions)<2){unset($data['x2'],$data['y2']);}
            if($positions===[]){$data['cmsArchived']=true;}
            $data['images']=[];foreach($this->rows('media_links',['point_id'=>$id]) as $link){$data['images'][]=$this->row('media',$link['media_id'])['url'];}
        } else {
            $data['name']=$row['title'];$data['gameMode']=$row['mode']==='all'?'regular':$row['mode'];$data['sourceStatus']=$needsReview?'unverified-coordinates':'editor';
            $positions=$this->rows('point_world_positions',['point_id'=>$id]);if($positions!==[]){$position=$positions[0];$data['position']=['x'=>$position['world_x'],'y'=>$position['world_y'],'z'=>$position['world_z']];}
            else{unset($data['position']);$data['cmsArchived']=true;}
            $data['category']=$data['cmsCategories'][0]??$data['category']??'quest';$data['kind']=str_starts_with($data['category'],'extract-')?'extract':$data['category'];
            if($keys!==[]){$data['keyNames']=array_map(fn($link)=>$this->row('items',$link['key_item_id'])['name'],$keys);}else{unset($data['keyNames']);}
            if($data['cmsQuestLinks']!==[]){$data=[...$data,...$data['cmsQuestLinks'][0]];}else{unset($data['questId'],$data['objectiveId']);}
        }
        if($needsReview&&is_array($previousPosition)&&isset($data['position'])){
            foreach(['x','y','z'] as $axis){if(abs((float)$previousPosition[$axis]-(float)$data['position'][$axis])>0.001){
                unset($data['hiddenByDefault'],$data['reviewReason']);$data['sourceStatus']='editor';break;
            }}
        }
        return $data;
    }
    private function external(string $type,int $id): ?string
    { return $this->row($type,$id)['external_id']??null; }
    private function reference(string $type,int $id,mixed $old=null): mixed
    {
        $row=$this->row($type,$id);$external=$row['external_id']??null;
        if($type==='locations'){$external=DB::table('wiki_location_aliases')->where(['location_id'=>$id,'scheme'=>'game'])->value('value')??$external??$row['slug'];}
        $values=$this->flatReferences($old);
        foreach($values as $value){if(WikiImporter::external($value)===$external){return $value;}}
        return is_array($values[0]??null)&&isset($values[0]['id'])?['id'=>$external,'name'=>$row['name']??$row['title']]:$external;
    }
    private function flatReferences(mixed $value): array
    { if(WikiImporter::external($value)!==null){return [$value];}$result=[];foreach(is_array($value)?$value:[] as $child){$result=[...$result,...$this->flatReferences($child)];}return $result; }
    private function unknown(string $type,mixed $old): array
    {
        return array_values(array_filter($this->flatReferences($old),function($ref)use($type){$id=WikiImporter::external($ref);
            return $type==='locations'?!DB::table('wiki_location_aliases')->where('value',$id)->exists():!DB::table('wiki_'.$type)->where('external_id',$id)->exists();}));
    }
    private function quest(int $id,string $area,?int $child=null): void
    {
        $row=$this->row('quests',$id);$legacy=!empty($row['legacy_quest_id'])?Quest::findOrFail($row['legacy_quest_id']):new Quest;
        $payload=$legacy->payload;$payload['id']=$row['external_id'];$payload['name']=$row['title'];$payload['description']=$row['description'];
        $payload['minPlayerLevel']=$row['level_req'];$payload['story']=(bool)$row['is_story'];$payload['kappaRequired']=(bool)$row['kappa_required'];
        if($row['wiki_url']!==null){$payload['wikiLink']=$row['wiki_url'];}
        $trader=!empty($row['trader_id'])?$this->row('traders',$row['trader_id']):null;
        if($trader){$payload['trader']=[...(is_array($payload['trader']??null)?$payload['trader']:[]),'id'=>$trader['external_id'],'name'=>$trader['name'],'imageLink'=>$trader['image_url']];}
        $payload['objectives']??=[];
        if(in_array($area,['objective','conditions','objective_locations','objective_points','objective_keys'],true)&&$child!==null){
            $objective=$this->row('objectives',$child);$position=null;
            foreach($payload['objectives'] as $i=>$goal){if(($goal['id']??null)===$objective['external_id']){$position=$i;break;}}
            $position??=count($payload['objectives']);$goal=$payload['objectives'][$position]??['id'=>$objective['external_id']];
            $goal=[...$goal,'description'=>$objective['description'],'type'=>$objective['type'],'optional'=>(bool)$objective['optional']];
            if($objective['archived']){$goal['cmsArchived']=true;}else{unset($goal['cmsArchived']);}
            if($objective['amount']!==null){$goal['count']=$objective['amount'];}else{unset($goal['count']);}
            if($area==='conditions'){
                $trees=$this->conditionTrees($child);$goal['cmsConditionGroups']=$trees;$purposes=[];
                foreach($trees as $tree){$purposes[$tree['purpose']][]=$this->conditionValues($tree,$goal[$tree['purpose']]??[]);}
                foreach(['items','item','questItem','usingWeapon','usingWeaponMods','wearing','notWearing'] as $purpose){
                    if(!isset($purposes[$purpose])&&!array_key_exists($purpose,$goal)){continue;}
                    $values=$purposes[$purpose]??[];$values=count($values)===1?$values[0]:$values;
                    $unknown=$this->unknown('items',$goal[$purpose]??[]);$values=[...$values,...$unknown];
                    $goal[$purpose]=in_array($purpose,['item','questItem'],true)?($values[0]??null):$values;
                }
            }
            if($area==='objective_locations'){$goal['maps']=[...array_map(fn($link)=>$this->reference('locations',$link['location_id'],$goal['maps']??[]),$this->rows('objective_locations',['objective_id'=>$child])),...$this->unknown('locations',$goal['maps']??[])];}
            if($area==='objective_keys'){$goal['requiredKeys']=[...array_map(fn($link)=>$this->reference('items',$link['key_item_id'],$goal['requiredKeys']??[]),$this->rows('objective_keys',['objective_id'=>$child])),...$this->unknown('items',$goal['requiredKeys']??[])];}
            if($area==='objective_points'){
                $links=$this->rows('objective_points',['objective_id'=>$child]);$goal['mapPoints']=[];
                $goal['zones']=array_values(array_filter($goal['zones']??[],fn($zone)=>empty($zone['cmsPoint'])));
                foreach($links as $link){$point=$this->row('map_points',$link['point_id']);$goal['mapPoints'][]=$point['external_id'];
                    foreach($this->rows('point_world_positions',['point_id'=>$link['point_id']]) as $world){$goal['zones'][]=['id'=>'cms-point-'.$point['external_id'],
                        'map'=>$this->reference('locations',$point['location_id']),'position'=>['x'=>$world['world_x'],'y'=>$world['world_y'],'z'=>$world['world_z']],'cmsPoint'=>true];}}
            }
            $payload['objectives'][$position]=$goal;
            $ordered=DB::table('wiki_objectives')->where('quest_id',$id)->pluck('order_index','external_id')->all();
            usort($payload['objectives'],fn($a,$b)=>($ordered[$a['id']]??PHP_INT_MAX)<=>($ordered[$b['id']]??PHP_INT_MAX));
            DB::table('wiki_objectives')->where('id',$child)->update(['source_hash'=>hash('sha256',json_encode($goal,JSON_THROW_ON_ERROR))]);
        }
        if($area==='requirements'){
            $payload['cmsPrerequisites']=$this->prerequisiteTrees($id);$payload['taskRequirements']=[];
            $groups=DB::table('wiki_prerequisite_groups')->where('quest_id',$id)->where('archived',0)->pluck('id');
            foreach(DB::table('wiki_quest_prerequisites')->whereIn('group_id',$groups)->where('archived',0)->get() as $edge){$payload['taskRequirements'][]=['task'=>$this->reference('quests',$edge->prerequisite_quest_id),'status'=>[$edge->status]];}
        }
        if(in_array($area,['quest_trader_requirements','quest_skill_requirements','quest_station_requirements'],true)){
            [$field,$catalog,$column,$level]=['quest_trader_requirements'=>['traderRequirements','traders','trader_id','loyalty_level'],
                'quest_skill_requirements'=>['skillRequirements','skills','skill_id','level'],'quest_station_requirements'=>['stationLevelRequirements','stations','station_id','level']][$area];
            $key=['traders'=>'trader','skills'=>'skill','stations'=>'station'][$catalog];
            $payload[$field]=array_map(fn($link)=>[$key=>$this->reference($catalog,$link[$column]),'level'=>$link[$level]],$this->rows($area,['quest_id'=>$id]));
        }
        if(str_starts_with($area,'reward_')&&$child!==null){$this->reward($payload,$child,$area);}
        if($area==='media'){$payload['media']=array_map(fn($link)=>$this->row('media',$link['media_id'])['url'],$this->rows('media_links',['quest_id'=>$id]));}
        $legacy->fill(['quest_id'=>$row['external_id'],'mode'=>$row['mode'],'name'=>$row['title'],'trader_id'=>$trader['external_id']??'',
            'published'=>(bool)($row['published']&&!$row['archived']),'payload'=>$payload]);$legacy->save();
        if(empty($row['legacy_quest_id'])){DB::table('wiki_quests')->where('id',$id)->update(['legacy_quest_id'=>$legacy->id]);}
        DB::table('wiki_projection_states')->updateOrInsert(['owner_type'=>'quest','owner_id'=>$legacy->id],['content_ref'=>$legacy->getRawOriginal('payload')]);
        $index=$this->dataset('raid/quest-index.json')['document'];$index[$row['mode']][$row['external_id']]=['id'=>$row['external_id'],'name'=>$row['title'],'trader'=>$trader['name']??'',
            'maps'=>DB::table('wiki_objective_locations as l')->join('wiki_objectives as o','o.id','=','l.objective_id')->join('wiki_locations as m','m.id','=','l.location_id')->where('o.quest_id',$id)->where('l.archived',0)->distinct()->pluck('m.slug')->all()];
        $this->write('raid/quest-index.json','',$index);
    }
    private function conditionTrees(int $objective,?int $parent=null): array
    {
        $result=[];foreach($this->rows('objective_groups',['objective_id'=>$objective,'parent_id'=>$parent]) as $group){$items=[];
            foreach($this->rows('objective_items',['group_id'=>$group['id']]) as $link){$items[]=['item'=>$this->external('items',$link['item_id']),'amount'=>$link['amount'],'foundInRaid'=>(bool)$link['found_in_raid'],'role'=>$link['role']];}
            $result[]=['source'=>$group['source_path'],'purpose'=>$group['purpose'],'operator'=>$group['operator'],'items'=>$items,'children'=>$this->conditionTrees($objective,$group['id'])];}
        return $result;
    }
    private function conditionValues(array $tree,mixed $old): array
    {
        $result=[];foreach($tree['items'] as $item){$id=DB::table('wiki_items')->where('external_id',$item['item'])->value('id');if($id){$result[]=$this->reference('items',$id,$old);}}
        foreach($tree['children'] as $child){$result[]=$this->conditionValues($child,$old);}return $result;
    }
    private function prerequisiteTrees(int $quest,?int $parent=null): array
    {
        $result=[];foreach($this->rows('prerequisite_groups',['quest_id'=>$quest,'parent_id'=>$parent]) as $group){$edges=[];
            foreach($this->rows('quest_prerequisites',['group_id'=>$group['id']]) as $edge){$edges[]=['quest'=>$this->external('quests',$edge['prerequisite_quest_id']),'status'=>$edge['status'],'hard'=>(bool)$edge['hard_requirement']];}
            $result[]=['source'=>$group['source_path'],'operator'=>$group['operator'],'edges'=>$edges,'children'=>$this->prerequisiteTrees($quest,$group['id'])];}
        return $result;
    }
    private function reward(array &$payload,int $setId,string $area): void
    {
        $set=$this->row('reward_sets',$setId);$field=$set['timing']==='finish'?'finishRewards':'startRewards';$payload[$field]??=[];
        if($set['archived']){$payload['cmsArchivedRewardSets'][$set['timing']]??=$payload[$field];$payload[$field]=[];if($set['timing']==='finish'){$payload['experience']=0;$payload['cmsRewards']=true;}return;}
        if(isset($payload['cmsArchivedRewardSets'][$set['timing']])){$payload[$field]=$payload['cmsArchivedRewardSets'][$set['timing']];unset($payload['cmsArchivedRewardSets'][$set['timing']]);}
        if($area==='reward_sets'){foreach(['reward_items','reward_standing','reward_skills','reward_unlocks'] as $part){$this->reward($payload,$setId,$part);}}
        if($set['timing']==='finish'){$payload['experience']=$set['experience'];$payload['cmsRewards']=true;}
        if($area==='reward_items'){$old=$payload[$field]['items']??[];$payload[$field]['items']=array_map(function($row)use($old){$previous=$old[$row['order_index']]??[];return [...$previous,'item'=>$this->reference('items',$row['item_id'],$previous['item']??null),'count'=>$row['amount'],'attributes'=>[...($previous['attributes']??[]),'foundInRaid'=>(bool)$row['found_in_raid']]];},$this->rows('reward_items',['reward_set_id'=>$setId]));
            foreach($old as $reward){if($this->unknown('items',$reward['item']??null)!==[]){$payload[$field]['items'][]=$reward;}}}
        if($area==='reward_standing'){$payload[$field]['traderStanding']=array_map(fn($row)=>['trader'=>$this->reference('traders',$row['trader_id']),'standing'=>$row['standing']],$this->rows('reward_standing',['reward_set_id'=>$setId]));}
        if($area==='reward_skills'){$payload[$field]['skillLevelReward']=array_map(fn($row)=>['skill'=>$this->reference('skills',$row['skill_id']),'level'=>$row['level']],$this->rows('reward_skills',['reward_set_id'=>$setId]));}
        if($area==='reward_unlocks'){
            foreach(['trader_id'=>['traderUnlock','traders'],'craft_id'=>['craftUnlock','crafts'],'offer_id'=>['offerUnlock','offers'],'location_id'=>['locationUnlock','locations'],'achievement_id'=>['achievement','achievements']] as $column=>[$name,$type]){
                $payload[$field][$name]=[];foreach($this->rows('reward_unlocks',['reward_set_id'=>$setId]) as $row){if(!empty($row[$column])){$payload[$field][$name][]=$this->reference($type,$row[$column]);}}}
        }
    }
}
