<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Читает предметный граф SQLite для карт и карточек, не открывая сессию игрока. */
final class WikiGraph
{
    private function query(string $type)
    { return DB::table('wiki_'.$type)->where('archived',0); }
    private function related(string $type,array $where): array
    { return $this->query($type)->where($where)->get()->map(fn($row)=>$this->clean((array)$row))->all(); }
    private function clean(array $row): array
    { unset($row['search_text'],$row['source_hash'],$row['legacy_quest_id'],$row['source_path'],$row['revision']);return $row; }
    public function read(string $path): array
    {
        if(preg_match('~^wiki/(points|quests)/(\d+)\.json$~',$path,$m)){
            return $m[1]==='points'?$this->point((int)$m[2]):$this->quest((int)$m[2]);
        }
        $catalog=['wiki/items.json'=>'items','wiki/keys.json'=>'keys','wiki/locations.json'=>'locations','wiki/points.json'=>'map_points','wiki/quests.json'=>'quests'];
        foreach(['maps','map_layers','documents','point_categories','traders','skills','stations','crafts','offers','versions'] as $type){$catalog['wiki/'.$type.'.json']=$type;}
        abort_unless(isset($catalog[$path]),404);$type=$catalog[$path];$query=$this->query($type);
        if($type==='quests'){$query->where('published',1);}
        if($type==='map_points'){
            $query->where('active',1);
            if(request()->filled('location')){$location=DB::table('wiki_location_aliases')->where('value',request('location'))->value('location_id');$query->where('location_id',$location??-1);}
            if(request()->filled('mode')){abort_unless(in_array(request('mode'),['pve','regular'],true),422);$query->whereIn('mode',['all',request('mode')]);}
            if(request()->filled('category')){$query->whereExists(fn($q)=>$q->selectRaw('1')->from('wiki_point_category_links as c')->whereColumn('c.point_id','wiki_map_points.id')->where('c.archived',0)->where('c.category',request('category')));}
        }
        if(request()->filled('q')){$query->whereRaw('instr(search_text,?)>0',[WikiSchema::search((string)request('q'))]);}
        $result=$query->orderBy('display_name')->paginate(50)->toArray();
        $result['data']=array_map(fn($row)=>$this->clean((array)$row),$result['data']);return $result;
    }
    private function point(int $id): array
    {
        $point=$this->query('map_points')->where('active',1)->find($id);abort_unless($point,404);$data=$this->clean((array)$point);
        $data['location']=$this->clean((array)$this->query('locations')->find($point->location_id));
        foreach(['point_positions'=>'positions','point_world_positions'=>'world_positions','point_category_links'=>'categories','extractions'=>'extractions'] as $type=>$name){$data[$name]=$this->related($type,['point_id'=>$id]);}
        foreach(['item_spawns'=>['items','item_id','items'],'key_usages'=>['items','key_item_id','keys'],'point_documents'=>['documents','document_id','documents'],'media_links'=>['media','media_id','media']] as $type=>[$catalog,$column,$name]){
            $data[$name]=[];foreach($this->related($type,['point_id'=>$id]) as $link){$record=$this->query($catalog)->find($link[$column]);if($record){$data[$name][]=['record'=>$this->clean((array)$record),'relation'=>$link];}}
        }
        $data['quests']=DB::table('wiki_objective_points as p')->join('wiki_objectives as o','o.id','=','p.objective_id')->join('wiki_quests as q','q.id','=','o.quest_id')
            ->where('p.point_id',$id)->where('p.archived',0)->where('o.archived',0)->where('q.archived',0)->where('q.published',1)
            ->get(['q.id','q.title','q.mode','o.id as objective_id','o.description as objective'])->all();return $data;
    }
    private function quest(int $id): array
    {
        $quest=$this->query('quests')->where('published',1)->find($id);abort_unless($quest,404);$data=$this->clean((array)$quest);
        $data['objectives']=[];
        $objectives=$this->related('objectives',['quest_id'=>$id]);usort($objectives,fn($a,$b)=>$a['order_index']<=>$b['order_index']);
        foreach($objectives as $objective){$oid=$objective['id'];$objective['groups']=$this->groups('objective',$oid);
            foreach(['objective_points','objective_keys','objective_locations'] as $type){$objective[$type]=$this->related($type,['objective_id'=>$oid]);}$data['objectives'][]=$objective;}
        $data['prerequisites']=$this->groups('prerequisite',$id);$data['rewards']=[];
        foreach($this->related('reward_sets',['quest_id'=>$id]) as $set){foreach(['reward_items','reward_standing','reward_skills','reward_unlocks'] as $type){$set[$type]=$this->related($type,['reward_set_id'=>$set['id']]);}$data['rewards'][]=$set;}
        return $data;
    }
    private function groups(string $kind,int $owner,?int $parent=null): array
    {
        $result=[];$field=$kind==='objective'?'objective_id':'quest_id';
        foreach($this->related($kind.'_groups',[$field=>$owner,'parent_id'=>$parent]) as $group){$type=$kind==='objective'?'objective_items':'quest_prerequisites';$group['relations']=$this->related($type,['group_id'=>$group['id']]);$group['children']=$this->groups($kind,$owner,$group['id']);$result[]=$group;}return $result;
    }
}
