<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{ContentDataset,Quest};
use Illuminate\Support\Facades\DB;

/** Переносит существующие сущности и связи в SQLite без изменения исходных файлов. */
final class WikiImporter
{
    private array $ids=[];
    private ?int $versionId=null;
    private ?\Closure $progress=null;
    private int $pointsImported=0;
    public function __construct(private WikiStore $store,private WikiSchema $schema,private WikiSync $sync) {}
    public function rebuild(?callable $progress=null): void
    {
        $this->progress=$progress===null?null:\Closure::fromCallable($progress);$this->pointsImported=0;
        $this->sync->run(fn()=>DB::transaction(function (): void {
            $force=!DB::table('wiki_projection_states')->where(['owner_type'=>'full','content_ref'=>'domain-v2'])->exists();
            $datasets=ContentDataset::orderByRaw("CASE WHEN path='maps.json' THEN 0 WHEN path='raid/maps.json' THEN 1 WHEN path LIKE 'raid/%' THEN 3 ELSE 2 END")->orderBy('path')->get();
            foreach($datasets as $dataset){if(!$this->isPoints($dataset->path)){($this->progress)?->__invoke('Справочник: '.$dataset->path);$this->dataset($dataset,$force);}}
            ($this->progress)?->__invoke('Определения квестов');
            foreach(Quest::orderBy('id')->cursor() as $quest){$this->questDefinition($quest);}
            $this->ids=[];
            $count=0;foreach(Quest::orderBy('id')->cursor() as $quest){$this->quest($quest,$force);if(++$count%100===0){($this->progress)?->__invoke('Квестов обработано: '.$count);}}
            foreach($datasets as $dataset){if($this->isPoints($dataset->path)){($this->progress)?->__invoke('Точки: '.$dataset->path);$this->dataset($dataset,$force);}}
            DB::table('wiki_projection_states')->updateOrInsert(['owner_type'=>'full','owner_id'=>0],['content_ref'=>'domain-v2']);
        }));
    }
    private function isPoints(string $path): bool
    { return $path==='spawns.json'||(preg_match('~^raid/(?:pve/)?[a-z-]+\.json$~',$path)&&!in_array($path,['raid/maps.json','raid/item-names.json','raid/loose-items.json'],true)); }
    private function unchanged(string $type,int $id,string $ref,bool $force): bool
    { return !$force&&DB::table('wiki_projection_states')->where(['owner_type'=>$type,'owner_id'=>$id,'content_ref'=>$ref])->exists(); }
    private function state(string $type,int $id,string $ref): void
    { DB::table('wiki_projection_states')->updateOrInsert(['owner_type'=>$type,'owner_id'=>$id],['content_ref'=>$ref]); }
    public function version(): int
    { return $this->versionId??=(int)(DB::table('wiki_versions')->where('code','current')->value('id')??$this->store->put('versions',['code'=>'current'],['patch'=>'Текущие данные','valid_from'=>'2026-10-15','archived'=>0])); }
    public function dataset(ContentDataset $dataset,bool $force=false): void
    {
        $path=$dataset->path;
        if(!preg_match('~^(maps|docs|spawns|guide/(items|quests|reference)-(pve|regular)|raid/(maps|loose-items|item-names|(?:pve/)?[a-z-]+))\.json$~',$path)){return;}
        $ref=(string)$dataset->getRawOriginal('payload');if($this->unchanged('dataset',$dataset->id,$ref,$force)){return;}
        $data=$dataset->content();$version=$this->version();
        if($path==='maps.json'){
            foreach($data as $i=>$map){if(!isset($map['id'],$map['name'])){continue;}
                $location=$this->location($map['id'],$map['name']);
                $id=$this->store->put('maps',['location_id'=>$location,'version_id'=>$version,'slug'=>'documents'],[
                    'image_url'=>$map['file']??'', 'width'=>max(1,(int)($map['width']??1)), 'height'=>max(1,(int)($map['height']??1)),
                    'name'=>$map['name'].' — документы']);
                $this->store->source($dataset->id,'/'.$i,'map',$id,'document-map');
            }
        } elseif($path==='raid/maps.json'){
            foreach($data as $i=>$map){$location=$this->location($map['id'],$map['name']??$map['displayName']);
                if(isset($map['mapInfo']['id'])){$this->alias($location,'game',$map['mapInfo']['id']);}
                $asset=$map['baseAsset']['path']??'';$dimensions=config('wiki-assets.'.$map['id'],['width'=>1,'height'=>1]);
                $id=$this->store->put('maps',['location_id'=>$location,'version_id'=>$version,'slug'=>'raid'],[
                    'name'=>($map['name']??$map['displayName']).' — рейдовая карта','image_url'=>$asset,
                    'width'=>$dimensions['width'],'height'=>$dimensions['height']]);
                $this->store->source($dataset->id,'/'.$i,'map',$id,'raid-map');
                foreach(['baseFloor'=>[$map['baseFloor']??[]],'floors'=>$map['floors']??[]] as $key=>$floors){
                    foreach($floors as $j=>$floor){if(!isset($floor['id'])){continue;}
                        $layer=$this->store->put('map_layers',['map_id'=>$id,'external_id'=>$floor['id']],['name'=>$floor['name']??$floor['id'],'order_index'=>$key==='baseFloor'?0:$j+1,'image_url'=>$floor['asset']['path']??null]);
                        $this->store->source($dataset->id,'/'.$i.'/'.$key.($key==='floors'?'/'.$j:''),'layer',$layer);
                    }
                }
            }
        } elseif($path==='docs.json'){
            foreach($data as $i=>$doc){$id=$this->store->put('documents',['external_id'=>$doc['id']],['name'=>$doc['name'],'category'=>$doc['category']??'document','description'=>$doc['description']??'','wiki_url'=>$doc['wikiLink']??null]);$this->store->source($dataset->id,'/'.$i,'document',$id);}
        } elseif($path==='raid/item-names.json'){
            foreach($data as $external=>$name){if(!is_string($name)){continue;}$id=$this->item(['id'=>$external,'name'=>$name]);$this->store->source($dataset->id,'/'.$external,'item',$id,'name');}
        } elseif($path==='spawns.json'){
            foreach($data as $i=>$point){$this->point($dataset,$point,'/'.$i,'document');}
        } elseif(str_starts_with($path,'raid/')&&isset($data['pois'],$data['mapId'])){
            foreach($data['pois'] as $i=>$point){$point['map']=$data['mapId'];$this->point($dataset,$point,'/pois/'.$i,'raid');}
        } else {
            foreach(['items'=>'items','questItems'=>'items','traders'=>'traders','maps'=>'locations','skills'=>'skills','hideout'=>'stations','achievements'=>'achievements'] as $field=>$type){
                foreach(is_array($data[$field]??null)?$data[$field]:[] as $i=>$record){if(!is_array($record)||!isset($record['id'],$record['name'])){continue;}
                    if($type==='items'){$id=$this->item($record);$entity='item';}
                    elseif($type==='locations'){$slug=$this->mapSlug($record['normalizedName']??$record['id']);$id=$this->location($slug,$record['name']);$this->alias($id,'game',$record['id']);$entity='location';}
                    else{$values=['name'=>$record['name']];if($type==='traders'){$values+=['slug'=>$record['normalizedName']??$record['id'],'image_url'=>$record['imageLink']??null];}
                        $id=$this->store->put($type,['external_id'=>$record['id']],$values);$entity=['traders'=>'trader','skills'=>'skill','stations'=>'station','achievements'=>'achievement'][$type];}
                    $this->store->source($dataset->id,'/'.$field.'/'.$this->escape((string)$i),$entity,$id);
                }
            }
            $this->ids=[];$this->crafts($dataset,$data);
        }
        $this->ids=[];$this->state('dataset',$dataset->id,$ref);
    }
    /** Обновляет только проверенные точки, если остальная проекция набора уже актуальна. */
    public function changedPoints(ContentDataset $dataset,array $ids,string $previousRef): void
    {
        if(!$this->unchanged('dataset',$dataset->id,$previousRef,false)){$this->dataset($dataset);return;}
        $data=$dataset->content();$found=[];
        foreach($data['pois']??[] as $i=>$point){if(!in_array($point['id']??null,$ids,true)){continue;}
            $point['map']=$data['mapId'];$this->point($dataset,$point,'/pois/'.$i,'raid');$found[]=$point['id'];}
        if(array_diff($ids,$found)!==[]){throw new \RuntimeException('Не все изменённые точки найдены в наборе.');}
        $this->ids=[];$this->state('dataset',$dataset->id,(string)$dataset->getRawOriginal('payload'));
    }

    /** Разделяет совпадающие служебные ID разных карт, сохраняя все прежние строки и публичные ID. */
    public function repairPointScopes(): int
    {
        $created=0;
        foreach(ContentDataset::where('path','like','raid/%')->orderBy('id')->cursor() as $dataset){
            if(!$this->isPoints($dataset->path)){continue;}$data=$dataset->content();if(!isset($data['pois'],$data['mapId'])){continue;}
            $location=$this->find('locations',$data['mapId']);if(!$location){continue;}
            $mode=str_contains($dataset->path,'/pve/')?'pve':'regular';
            foreach(array_chunk($data['pois'],100,true) as $batch){$created+=$this->sync->run(fn()=>DB::transaction(function()use($batch,$dataset,$data,$location,$mode): int {
            $added=0;foreach($batch as $i=>$point){
                $old=DB::table('wiki_map_points')->where('external_id',$point['id'])->where('mode',$mode)->first();
                if(!$old||(int)$old->location_id===$location){continue;}
                $key=$this->pointExternal($point['id'],$mode,$location,'raid');
                $existing=DB::table('wiki_map_points')->where('external_id',$key)->where('mode',$mode)->value('id');
                if($existing){$this->store->source($dataset->id,'/pois/'.$i,'point',(int)$existing,'raid-point');continue;}
                $point['map']=$data['mapId'];$this->point($dataset,$point,'/pois/'.$i,'raid');$added++;
            }return $added;
            }));}
            $this->ids=[];
        }
        return $created;
    }

    private function pointExternal(string $publicId,string $mode,int $location,string $kind): string
    {
        $row=DB::table('wiki_map_points')->where('external_id',$publicId)->where('mode',$mode)->first();
        return !$row||(int)$row->location_id===$location?$publicId:'scoped-'.hash('sha256',$kind."\0".$mode."\0".$location."\0".$publicId);
    }

    private function item(array $record): int
    {
        $old=DB::table('wiki_items')->where('external_id',$record['id'])->first();
        $values=['name'=>$record['name'],'category'=>$record['category']['normalizedName']??$old?->category??'item','archived'=>(int)($record['cmsArchived']??false)];
        foreach(['description'=>'description','iconLink'=>'icon_url','icon'=>'icon_url','wikiLink'=>'wiki_url','width'=>'grid_width','height'=>'grid_height','weight'=>'weight'] as $from=>$to){if(isset($record[$from])&&is_scalar($record[$from])){$values[$to]=$record[$from];}}
        $values['search_text']=WikiSchema::search($record['name'].' '.($record['shortName']??'').' '.($record['normalizedName']??''));
        $id=$this->store->put('items',['external_id'=>$record['id']],$values);
        $categories=array_column($record['categories']??[],'normalizedName');
        if(isset($record['cmsKey'])||in_array('keys',$categories,true)||in_array('key',$categories,true)||preg_match('/ключ|keycard|key$/iu',$record['name'])){
            $this->store->put('keys',['item_id'=>$id],['description'=>$record['cmsKey']['description']??$record['description']??'','max_uses'=>$record['cmsKey']['maxUses']??null,'archived'=>(int)($record['cmsKey']['archived']??false)]);
        }
        return $id;
    }
    private function location(string $slug,string $name): int
    { $id=$this->store->put('locations',['slug'=>$slug],['name'=>$name]);$this->alias($id,'slug',$slug);return $id; }
    private function alias(int $id,string $scheme,string $value): void
    { DB::table('wiki_location_aliases')->updateOrInsert(compact('scheme','value'),['location_id'=>$id]);unset($this->ids['locations']); }
    private function mapSlug(string $slug): string
    { return $slug; }
    public static function external(mixed $reference): ?string
    { return is_string($reference)?$reference:(is_array($reference)?($reference['id']??null):null); }
    private function find(string $type,mixed $reference,string $scope='',string $field=''): ?int
    {
        $external=self::external($reference);if($external===null||$external===''){return null;}
        if(!isset($this->ids[$type])){
            $this->ids[$type]=$type==='locations'?DB::table('wiki_location_aliases')->pluck('location_id','value')->all():DB::table('wiki_'.$type)->pluck($type==='keys'?'item_id':'id','external_id')->all();
        }
        $id=$this->ids[$type][$external]??null;
        if($id===null&&$scope!==''){$this->store->issue($scope,$external,$field,'Ссылка отсутствует в исходном справочнике; значение сохранено в файле.');}
        return $id===null?null:(int)$id;
    }
    public function questDefinition(Quest $quest): int
    {
        $p=$quest->payload;$trader=$this->find('traders',$p['trader']??$quest->trader_id);
        return $this->store->put('quests',['external_id'=>$quest->quest_id,'mode'=>$quest->mode,'version_id'=>$this->version()],
            ['legacy_quest_id'=>$quest->id,'trader_id'=>$trader,'title'=>$quest->name,'description'=>$p['description']??'',
                'wiki_url'=>$p['wikiLink']??null,'level_req'=>max(0,(int)($p['minPlayerLevel']??0)),'is_story'=>(int)($p['story']??false),
                'kappa_required'=>(int)($p['kappaRequired']??false),'published'=>(int)$quest->published]);
    }
    public function quest(Quest $quest,bool $force=false): void
    {
        $ref=(string)$quest->getRawOriginal('payload');if($this->unchanged('quest',$quest->id,$ref,$force)){return;}
        $id=$this->questDefinition($quest);$p=$quest->payload;
        foreach($p['objectives']??[] as $i=>$goal){if(!isset($goal['id'])){continue;}
            $type=$goal['type']??'custom';$this->store->put('objective_types',['code'=>$type],['name'=>$this->objectiveName($type)]);
            $hash=hash('sha256',json_encode($goal,JSON_THROW_ON_ERROR));
            $old=DB::table('wiki_objectives')->where(['quest_id'=>$id,'external_id'=>$goal['id']])->first();
            $objective=$this->store->put('objectives',['quest_id'=>$id,'external_id'=>$goal['id']],['type'=>$type,
                'description'=>$goal['description']??'','order_index'=>$i,'optional'=>(int)($goal['optional']??false),'amount'=>$goal['count']??null,'source_hash'=>$hash,'archived'=>(int)($goal['cmsArchived']??false)]);
            if(!$force&&$old?->source_hash===$hash){continue;}
            $groups=DB::table('wiki_objective_groups')->where('objective_id',$objective)->pluck('id');
            DB::table('wiki_objective_items')->whereIn('group_id',$groups)->update(['archived'=>1]);
            DB::table('wiki_objective_groups')->where('objective_id',$objective)->update(['archived'=>1]);
            if(isset($goal['cmsConditionGroups'])){foreach($goal['cmsConditionGroups'] as $tree){$this->conditionTree($objective,$tree,null);}}
            else{foreach(['items','item','questItem','usingWeapon','usingWeaponMods','wearing','notWearing'] as $field){
                if(isset($goal[$field])){$this->itemGroup($objective,$goal[$field],'/'.$field,$field,null,$goal);}
            }}
            DB::table('wiki_objective_locations')->where('objective_id',$objective)->update(['archived'=>1]);
            foreach($goal['maps']??[] as $map){$location=$this->find('locations',$map,'quest:'.$quest->id,'maps');if($location!==null){$this->store->put('objective_locations',['objective_id'=>$objective,'location_id'=>$location],['archived'=>0]);}}
            DB::table('wiki_objective_keys')->where('objective_id',$objective)->update(['archived'=>1]);
            foreach($this->flatReferences($goal['requiredKeys']??[]) as $key){$item=$this->find('items',$key);if($item){$this->store->put('keys',['item_id'=>$item],[]);$this->store->put('objective_keys',['objective_id'=>$objective,'key_item_id'=>$item],['archived'=>0]);}}
            DB::table('wiki_objective_points')->where('objective_id',$objective)->update(['archived'=>1]);
            foreach($goal['mapPoints']??[] as $point){$pointId=DB::table('wiki_map_points')->where('external_id',self::external($point))->whereIn('mode',['all',$quest->mode])->value('id');if($pointId){$this->store->put('objective_points',['objective_id'=>$objective,'point_id'=>$pointId],['archived'=>0]);}}
        }
        $this->requirements($id,$quest,$p);$this->rewards($id,$p);$this->state('quest',$quest->id,$ref);
    }
    private function flatReferences(mixed $value): array
    {
        if(self::external($value)!==null){return [$value];}$result=[];
        foreach(is_array($value)?$value:[] as $child){$result=[...$result,...$this->flatReferences($child)];}return $result;
    }
    private function conditionTree(int $objective,array $tree,?int $parent): void
    {
        $group=$this->store->put('objective_groups',['objective_id'=>$objective,'source_path'=>$tree['source']],
            ['parent_id'=>$parent,'purpose'=>$tree['purpose'],'operator'=>$tree['operator'],'archived'=>0]);
        foreach($tree['items'] as $link){$item=$this->find('items',$link['item']);if($item){$this->store->put('objective_items',['group_id'=>$group,'item_id'=>$item,'role'=>$link['role']],
            ['amount'=>$link['amount'],'found_in_raid'=>(int)$link['foundInRaid'],'archived'=>0]);}}
        foreach($tree['children'] as $child){$this->conditionTree($objective,$child,$group);}
    }
    private function itemGroup(int $objective,mixed $value,string $path,string $purpose,?int $parent,array $goal): void
    {
        $group=$this->store->put('objective_groups',['objective_id'=>$objective,'source_path'=>$path],['parent_id'=>$parent,'purpose'=>$purpose,'operator'=>$purpose==='usingWeaponMods'||$purpose==='wearing'?'and':'or','archived'=>0]);
        $values=is_array($value)&&array_is_list($value)?$value:[$value];
        foreach($values as $i=>$reference){
            if(is_array($reference)&&array_is_list($reference)){$this->itemGroup($objective,$reference,$path.'/'.$i,$purpose,$group,$goal);continue;}
            $item=$this->find('items',$reference,'objective:'.$objective,$purpose);if($item===null){continue;}
            $role=match($purpose){'usingWeapon'=>'weapon','usingWeaponMods'=>'mod','wearing'=>'wear','notWearing'=>'not_wear',default=>match($goal['type']??''){'giveItem','giveQuestItem'=>'handover','plantItem'=>'plant','mark'=>'mark',default=>'obtain'}};
            $this->store->put('objective_items',['group_id'=>$group,'item_id'=>$item,'role'=>$role],['amount'=>max(0.000001,(float)($goal['count']??1)),'found_in_raid'=>(int)($goal['foundInRaid']??false),'archived'=>0]);
        }
    }
    private function requirements(int $id,Quest $quest,array $payload): void
    {
        if(isset($payload['cmsPrerequisites'])){
            $groups=DB::table('wiki_prerequisite_groups')->where('quest_id',$id)->pluck('id');
            DB::table('wiki_quest_prerequisites')->whereIn('group_id',$groups)->update(['archived'=>1]);
            DB::table('wiki_prerequisite_groups')->where('quest_id',$id)->update(['archived'=>1]);
            foreach($payload['cmsPrerequisites'] as $tree){$this->prerequisiteTree($id,$quest->mode,$tree,null);}
        }else{
        $group=$this->store->put('prerequisite_groups',['quest_id'=>$id,'source_path'=>'/taskRequirements'],['operator'=>'and','archived'=>0]);
        DB::table('wiki_quest_prerequisites')->where('group_id',$group)->update(['archived'=>1]);
        foreach($payload['taskRequirements']??[] as $requirement){$external=self::external($requirement['task']??null);
            $parent=DB::table('wiki_quests')->where(['external_id'=>$external,'mode'=>$quest->mode,'version_id'=>$this->version()])->value('id');
            if(!$parent){if($external){$this->store->issue('quest:'.$id,$external,'taskRequirements','Квест отсутствует в данном режиме.');}continue;}
            foreach((array)($requirement['status']??['complete']) as $status){if(!in_array($status,['complete','active','failed','available'],true)){continue;}
                $this->store->put('quest_prerequisites',['group_id'=>$group,'prerequisite_quest_id'=>$parent,'status'=>$status],['hard_requirement'=>1,'archived'=>0]);}
        }
        }
        foreach(['traderRequirements'=>['quest_trader_requirements','traders','trader','trader_id','loyalty_level'],
            'skillRequirements'=>['quest_skill_requirements','skills','skill','skill_id','level'],
            'stationLevelRequirements'=>['quest_station_requirements','stations','station','station_id','level']] as $field=>[$type,$catalog,$key,$column,$level]){
            DB::table('wiki_'.$type)->where('quest_id',$id)->update(['archived'=>1]);
            foreach($payload[$field]??[] as $requirement){$target=$this->find($catalog,$requirement[$key]??null);if($target){$this->store->put($type,['quest_id'=>$id,$column=>$target],[$level=>max(0,(float)($requirement['level']??0)),'archived'=>0]);}}
        }
    }
    private function prerequisiteTree(int $quest,string $mode,array $tree,?int $parent): void
    {
        $group=$this->store->put('prerequisite_groups',['quest_id'=>$quest,'source_path'=>$tree['source']],['parent_id'=>$parent,'operator'=>$tree['operator'],'archived'=>0]);
        foreach($tree['edges'] as $edge){$target=DB::table('wiki_quests')->where(['external_id'=>$edge['quest'],'mode'=>$mode,'version_id'=>$this->version()])->value('id');
            if($target){$this->store->put('quest_prerequisites',['group_id'=>$group,'prerequisite_quest_id'=>$target,'status'=>$edge['status']],['hard_requirement'=>(int)$edge['hard'],'archived'=>0]);}}
        foreach($tree['children'] as $child){$this->prerequisiteTree($quest,$mode,$child,$group);}
    }
    private function rewards(int $quest,array $payload): void
    {
        foreach(['start'=>'startRewards','finish'=>'finishRewards'] as $timing=>$field){$data=$payload[$field]??[];
            $set=$this->store->put('reward_sets',['quest_id'=>$quest,'timing'=>$timing],['experience'=>max(0,(int)($timing==='finish'?($payload['experience']??0):0))]);
            foreach(['reward_items','reward_standing','reward_skills','reward_unlocks'] as $type){DB::table('wiki_'.$type)->where('reward_set_id',$set)->update(['archived'=>1]);}
            foreach($data['items']??[] as $i=>$reward){$item=$this->find('items',$reward['item']??null,'quest:'.$quest,'rewards');if($item){$this->store->put('reward_items',['reward_set_id'=>$set,'order_index'=>$i],['item_id'=>$item,'amount'=>max(0.000001,(float)($reward['count']??1)),'found_in_raid'=>(int)($reward['attributes']['foundInRaid']??false),'archived'=>0]);}}
            foreach($data['traderStanding']??[] as $reward){$trader=$this->find('traders',$reward['trader']??null);if($trader){$this->store->put('reward_standing',['reward_set_id'=>$set,'trader_id'=>$trader],['standing'=>(float)($reward['standing']??0),'archived'=>0]);}}
            foreach($data['skillLevelReward']??[] as $reward){$skill=$this->find('skills',$reward['skill']??null);if($skill){$this->store->put('reward_skills',['reward_set_id'=>$set,'skill_id'=>$skill],['level'=>max(0,(float)($reward['level']??0)),'archived'=>0]);}}
            foreach(['traderUnlock'=>['traders','trader_id'],'craftUnlock'=>['crafts','craft_id'],'locationUnlock'=>['locations','location_id'],'achievement'=>['achievements','achievement_id']] as $name=>[$catalog,$column]){
                foreach($data[$name]??[] as $reference){$target=$this->find($catalog,$reference);if($target){$this->store->put('reward_unlocks',['reward_set_id'=>$set,$column=>$target],['archived'=>0]);}}
            }
        }
    }
    private function crafts(ContentDataset $dataset,array $data): void
    {
        foreach($data['crafts']??[] as $i=>$craft){$station=$this->find('stations',$craft['station']??null);$item=$this->find('items',$craft['productItem']['item']??null);if(!$station||!$item){continue;}
            $id=$this->store->put('crafts',['external_id'=>$craft['id']],['station_id'=>$station,'item_id'=>$item,'level'=>max(0,(int)($craft['level']??0)),
                'amount'=>max(0.000001,(float)($craft['productItem']['count']??1)),'duration_seconds'=>max(0,(int)($craft['duration']??0))]);$this->store->source($dataset->id,'/crafts/'.$i,'craft',$id);
            foreach($craft['requiredItems']??[] as $ingredient){$item=$this->find('items',$ingredient['item']??null);if($item){$this->store->put('craft_ingredients',['craft_id'=>$id,'item_id'=>$item],['amount'=>max(0.000001,(float)($ingredient['count']??1)),'consumed'=>1]);}}
        }
        foreach($data['barters']??[] as $i=>$offer){$trader=$this->find('traders',$offer['trader']??null);$item=$this->find('items',$offer['offeredItem']['item']??null);if($trader&&$item){$id=$this->store->put('offers',['external_id'=>$offer['id']],['trader_id'=>$trader,'item_id'=>$item,'level'=>max(0,(int)($offer['minTraderLevel']??0))]);$this->store->source($dataset->id,'/barters/'.$i,'offer',$id);}}
    }
    private function point(ContentDataset $dataset,array $point,string $pointer,string $kind): void
    {
        $location=$this->find('locations',$point['map']??null,'dataset:'.$dataset->id,'map');if(!$location||empty($point['id'])){return;}
        $mode=$kind==='document'?'all':(str_contains($dataset->path,'/pve/')?'pve':'regular');
        $external=$this->pointExternal($point['id'],$mode,$location,$kind);
        $id=$this->store->put('map_points',['external_id'=>$external,'mode'=>$mode],['location_id'=>$location,'title'=>$point['caption']??$point['name']??'Точка',
            'description'=>$point['description']??'','source_kind'=>$kind,'active'=>(int)empty($point['cmsArchived']),'archived'=>(int)!empty($point['cmsArchived'])]);
        $this->store->source($dataset->id,$pointer,'point',$id,$kind.'-point');
        foreach(['point_category_links','point_positions','point_world_positions','point_documents','item_spawns','key_usages','media_links'] as $type){DB::table('wiki_'.$type)->where('point_id',$id)->update(['archived'=>1]);}
        $category=$kind==='document'?'document':($point['category']??'quest');
        foreach($point['cmsCategories']??[$category] as $code){$this->store->put('point_categories',['code'=>$code],['name'=>DB::table('wiki_point_categories')->where('code',$code)->value('name')??$this->categoryName($code)]);$this->store->put('point_category_links',['point_id'=>$id,'category'=>$code],['archived'=>0]);}
        foreach($point['cmsDocuments']??[] as $ref){$doc=$this->find('documents',$ref);if($doc){$this->store->put('point_documents',['point_id'=>$id,'document_id'=>$doc],['archived'=>0]);}}
        if($kind==='document'){
            $map=DB::table('wiki_maps')->where(['location_id'=>$location,'slug'=>'documents'])->value('id');
            foreach(isset($point['cmsPositions'])?[]:[['x','y'],['x2','y2']] as $order=>[$x,$y]){if($map&&isset($point[$x],$point[$y])&&$point[$x]>=0&&$point[$x]<=100&&$point[$y]>=0&&$point[$y]<=100){
                $layer=null;if(!empty($point['floor'])){$layer=$this->store->put('map_layers',['map_id'=>$map,'external_id'=>$point['floor']],['name'=>$point['floor']==='First_Floor'?'1 этаж':$point['floor']]);}
                $this->store->put('point_positions',['point_id'=>$id,'map_id'=>$map,'order_index'=>$order],['location_id'=>$location,'layer_id'=>$layer,'x_percent'=>$point[$x],'y_percent'=>$point[$y],'archived'=>0]);}}
            $document=$this->find('documents',$point['doc']??null);if($document){$this->store->put('point_documents',['point_id'=>$id,'document_id'=>$document],['archived'=>0]);}
            foreach($point['images']??[] as $i=>$url){if(!is_string($url)){continue;}$media=$this->store->put('media',['url'=>$url],['caption'=>$point['caption']??'']);$this->store->put('media_links',['media_id'=>$media,'point_id'=>$id],['order_index'=>$i,'archived'=>0]);}
        } elseif(!isset($point['cmsWorldPositions'])&&isset($point['position']['x'],$point['position']['y'],$point['position']['z'])){
            $this->store->put('point_world_positions',['point_id'=>$id,'order_index'=>0],['world_x'=>$point['position']['x'],'world_y'=>$point['position']['y'],'world_z'=>$point['position']['z'],'archived'=>0]);
        }
        foreach($point['cmsItemSpawns']??array_map(fn($item)=>['item'=>$item,'amount'=>1],$point['itemIds']??$point['items']??[]) as $link){$item=$this->find('items',$link['item']);if($item){$this->store->put('item_spawns',['point_id'=>$id,'item_id'=>$item],['amount'=>$link['amount'],'spawn_chance'=>$link['spawn_chance']??null,'notes'=>$link['notes']??'','archived'=>0]);}}
        foreach($point['cmsKeyUsages']??array_map(fn($item)=>['item'=>$item,'usage_type'=>'unlock'],$point['keyIds']??$point['keys']??[]) as $link){$item=$this->find('items',$link['item']);if($item){$this->store->put('keys',['item_id'=>$item],[]);$this->store->put('key_usages',['point_id'=>$id,'key_item_id'=>$item,'usage_type'=>$link['usage_type']],['notes'=>$link['notes']??'','archived'=>0]);}}
        foreach($point['cmsPositions']??[] as $position){$version=DB::table('wiki_versions')->where('code',$position['version']['code'])->value('id')??$this->store->put('versions',['code'=>$position['version']['code']],$position['version']);
            $map=DB::table('wiki_maps')->where(['location_id'=>$location,'version_id'=>$version,'slug'=>$position['map']['slug']])->value('id')??$this->store->put('maps',['location_id'=>$location,'version_id'=>$version,'slug'=>$position['map']['slug']],$position['map']);
            $layer=null;if($position['layer']){$layer=DB::table('wiki_map_layers')->where(['map_id'=>$map,'external_id'=>$position['layer']['external_id']])->value('id')??$this->store->put('map_layers',['map_id'=>$map,'external_id'=>$position['layer']['external_id']],$position['layer']);}
            $this->store->put('point_positions',['point_id'=>$id,'map_id'=>$map,'order_index'=>$position['order_index']],['location_id'=>$location,'layer_id'=>$layer,'x_percent'=>$position['x_percent'],'y_percent'=>$position['y_percent'],'archived'=>0]);}
        foreach($point['cmsWorldPositions']??[] as $position){$this->store->put('point_world_positions',['point_id'=>$id,'order_index'=>$position['order_index']],$position+['archived'=>0]);}
        foreach($point['cmsMedia']??[] as $media){$record=$this->store->put('media',['url'=>$media['url']],array_diff_key($media,['order_index'=>true]));$this->store->put('media_links',['point_id'=>$id,'media_id'=>$record],['order_index'=>$media['order_index'],'archived'=>0]);}
        if(isset($point['cmsQuestLinks'])){DB::table('wiki_objective_points')->where('point_id',$id)->update(['archived'=>1]);}
        if(isset($point['objectiveId'])&&!isset($point['cmsQuestLinks'])){
            $objective=DB::table('wiki_objectives as o')->join('wiki_quests as q','q.id','=','o.quest_id')->where('o.external_id',$point['objectiveId'])->where('q.mode',$mode)->where('q.external_id',$point['questId']??'')->value('o.id');
            if($objective){$this->store->put('objective_points',['objective_id'=>$objective,'point_id'=>$id],['archived'=>0]);}
        }
        foreach($point['cmsQuestLinks']??[] as $link){$objective=DB::table('wiki_objectives as o')->join('wiki_quests as q','q.id','=','o.quest_id')->where('o.external_id',$link['objectiveId'])->where('q.external_id',$link['questId'])->where('q.mode',$link['mode'])->value('o.id');
            if($objective){$this->store->put('objective_points',['objective_id'=>$objective,'point_id'=>$id],['archived'=>0]);}}
        if(str_starts_with($category,'extract-')||$category==='transit'){
            $type=['extract-pmc'=>'pmc','extract-scav'=>'scav','extract-shared'=>'shared','transit'=>'transit'][$category]??'shared';
            $this->store->put('extractions',['point_id'=>$id],['type'=>$type,'requirements'=>$point['description']??'']);
        }
        $this->store->put('map_points',['id'=>$id],[]);
        if(++$this->pointsImported%1000===0){($this->progress)?->__invoke('Точек обработано: '.$this->pointsImported);}
    }
    public function objectiveName(string $type): string
    { return ['visit'=>'Посетить место','findItem'=>'Найти предмет','giveItem'=>'Передать предмет','findQuestItem'=>'Найти квестовый предмет','giveQuestItem'=>'Передать квестовый предмет','shoot'=>'Уничтожить цель','extract'=>'Выйти с локации','mark'=>'Установить маяк','plantItem'=>'Разместить предмет','buildWeapon'=>'Собрать оружие','skill'=>'Развить навык','custom'=>'Другая цель'][$type]??$type; }
    private function categoryName(string $code): string
    { return ['document'=>'Документ','quest'=>'Квестовое место','locked-door'=>'Запертая дверь','loose-loot'=>'Предметы вне контейнеров','loot'=>'Контейнер с лутом','extract-pmc'=>'Выход ЧВК','extract-scav'=>'Выход Дикого','extract-shared'=>'Общий выход','transit'=>'Переход','key_spawn'=>'Спавн ключа','key_usage'=>'Использование ключа'][$code]??$code; }
    private function escape(string $key): string { return str_replace(['~','/'],['~0','~1'],$key); }
}
