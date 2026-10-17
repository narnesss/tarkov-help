<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{ContentDataset,Quest};
use Illuminate\Support\Facades\DB;

/** Собирает данные одной карточки; исходные записи, ID и личный прогресс не изменяет. */
final class PageContent
{
    private function dataset(string $path): array
    { return ContentDataset::where('path',$path)->first()?->content()??[]; }

    public function boss(array $catalog,array $reference,string $id): array
    {
        $profiles=array_values(array_filter($catalog['profiles']??[],fn($row)=>empty($row['cmsArchived'])));
        $profile=collect($profiles)->first(fn($row)=>($row['id']??null)===$id||($row['slug']??null)===$id);abort_unless($profile,404);
        $goons=['bossKnight','followerBigPipe','followerBirdEye'];
        $pairs=['bossKilla'=>'bossKillaAgro','bossKillaAgro'=>'bossKilla','bossTagilla'=>'bossTagillaAgro','bossTagillaAgro'=>'bossTagilla','bossWedge'=>'bossWedgeLab','bossWedgeLab'=>'bossWedge'];
        $related=in_array($profile['id'],$goons,true)?$goons:[$pairs[$profile['id']]??''];
        $links=array_map(fn($row)=>array_intersect_key($row,array_flip(['id','slug','name','healthTotal'])),array_values(array_filter($profiles,fn($row)=>$row['id']!==$profile['id']&&in_array($row['id'],$related,true))));
        $mob=$profile['spawnParentMobId']??$profile['id'];$maps=[];
        foreach($reference['maps']??[] as $map){
            if(!empty($map['cmsArchived']))continue;
            $spawns=array_values(array_filter($map['bosses']??[],fn($row)=>($row['mob']??null)===$mob&&empty($row['cmsArchived'])));
            if(!$spawns&&!in_array($map['normalizedName']??'', $profile['canonicalMapSlugs']??[],true))continue;
            $maps[]=[...array_intersect_key($map,array_flip(['id','name','normalizedName'])),'bosses'=>$spawns];
        }
        return ['profiles'=>[$profile,...$links],'reference'=>['meta'=>$reference['meta']??[],'maps'=>$maps]];
    }

    public function quest(array $data,array $task,string $mode): array
    {
        $id=$task['id'];$choices=$this->dataset('guide/quest-choices.json');$ids=[];
        foreach([...($task['taskRequirements']??[]),...($task['failConditions']??[])] as $condition){$value=$condition['task']??null;$other=is_array($value)?($value['id']??null):$value;if(is_string($other))$ids[]=$other;}
        $choices['groups']=array_values(array_filter($choices['groups']??[],fn($group)=>in_array($id,array_merge(...($group['options']??[[]])),true)&&(!isset($group['modes'])||in_array($mode,$group['modes'],true))));
        foreach($choices['groups'] as $group){$ids=[...$ids,...array_merge(...$group['options'])];}
        foreach(['branchNotices','notes'] as $key){$choices[$key]=array_values(array_filter($choices[$key]??[],fn($row)=>in_array($id,$row['questIds']??[],true)));}
        $wiki=DB::table('wiki_quests')->where('external_id',$id)->where('mode',$mode)->where('archived',0)->where('published',1)->pluck('id');
        if($wiki->isNotEmpty()){
            $successors=DB::table('wiki_quest_prerequisites as r')->join('wiki_prerequisite_groups as g','g.id','=','r.group_id')->join('wiki_quests as q','q.id','=','g.quest_id')
                ->whereIn('r.prerequisite_quest_id',$wiki)->where('r.archived',0)->where('g.archived',0)->where('q.archived',0)->where('q.published',1)->where('q.mode',$mode)->pluck('q.external_id')->all();
            $ids=[...$ids,...$successors];
            $next=DB::table('wiki_quests')->whereIn('external_id',$successors)->where('mode',$mode)->where('archived',0)->where('published',1)->pluck('id');
            $ids=[...$ids,...DB::table('wiki_quest_prerequisites as r')->join('wiki_prerequisite_groups as g','g.id','=','r.group_id')->join('wiki_quests as q','q.id','=','g.quest_id')->whereIn('r.prerequisite_quest_id',$next)->where('r.archived',0)->where('g.archived',0)->where('q.archived',0)->where('q.published',1)->where('q.mode',$mode)->pluck('q.external_id')->all()];
        }
        $rows=Quest::where('mode',$mode)->where('published',1)->whereIn('quest_id',array_unique($ids))->where('quest_id','<>',$id)->orderBy('id')->get();
        $context=[];$second=[];foreach($rows as $row){$brief=PublicCatalog::quest($row->payload);$context[]=$brief;foreach($brief['taskRequirements']??[] as $requirement){$ref=$requirement['task']??null;$ref=is_array($ref)?($ref['id']??null):$ref;if(is_string($ref))$second[]=$ref;}}
        $known=[$id,...array_column($context,'id')];foreach(Quest::where('mode',$mode)->where('published',1)->whereIn('quest_id',array_diff($second,$known))->orderBy('id')->get() as $row){$context[]=PublicCatalog::quest($row->payload);}
        $mapIndex=$this->dataset('guide/quest-map-index.json');$maps=['modes'=>[$mode=>[$id=>$mapIndex['modes'][$mode][$id]??[]]]];unset($mapIndex);
        $support=$this->dataset('guide/quest-reward-support.json');$barters=$support['modes'][$mode]['barters']??[];
        $support['modes']=[$mode=>['barters'=>array_values(array_filter($barters,fn($row)=>($row['taskUnlock']??null)===$id))]];
        $conditions=$this->dataset('guide/quest-conditions.json');$conditions['quests']=isset($conditions['quests'][$id])?[$id=>$conditions['quests'][$id]]:[];
        $used=[];$visit=function($value) use(&$visit,&$used){if(is_array($value)){foreach($value as $child)$visit($child);}elseif(is_string($value))$used[$value]=true;};$visit($task);$visit($context);$visit($support['modes']);
        foreach(['maps','traders','questItems','achievements','items','skills','hideout'] as $key){$data[$key]=array_values(array_filter($data[$key]??[],fn($row)=>isset($used[$row['id']??''])));}
        $support['achievements']=array_intersect_key($support['achievements']??[],$used);
        $data['guideLinks']=isset($data['guideLinks'][$id])?[$id=>$data['guideLinks'][$id]]:[];
        $data=array_intersect_key($data,array_flip(['meta','maps','traders','questItems','achievements','items','skills','hideout','guideLinks','cms']));
        $data['contextTasks']=$context;$data['partial']=true;
        $data['ui']=['choices'=>$choices,'questMaps'=>$maps,'rewardSupport'=>$support,'conditions'=>$conditions,'ticketMap'=>($id==='68da33fe00868edcb6025ac4'||($task['normalizedName']??'')==='the-ticket')?$this->dataset('guide/ticket-map.json'):[],'uiReady'=>true,'questId'=>$id];
        $localization=$this->dataset('guide/media-localization.json');$task=$this->localize($task,$this->mediaImages($localization));
        $data['selectedTask']=$task;
        return $data;
    }

    public static function mediaRevision(): string
    { $file=self::mediaFile();return $file!==null?hash_file('sha256',$file):'none'; }

    private static function mediaFile(): ?string
    {
        foreach([database_path('content-seed/datasets/guide/media-localization.json.gz'),public_path('data/guide/media-localization.json.gz'),base_path('build/content-seed/datasets/guide/media-localization.json.gz')] as $file){if(is_file($file))return $file;}
        return null;
    }

    /** Файлы перевода читает PHP; редакторские значения имеют приоритет, frontend таблицу не загружает. */
    private function mediaImages(array $current): array
    {
        $file=self::mediaFile();$defaults=[];
        if($file!==null){$defaults=json_decode(gzdecode(file_get_contents($file)),true,128,JSON_THROW_ON_ERROR)['images']??[];}
        foreach($current['images']??[] as $source=>$patch){if(!is_array($patch))continue;$defaults[$source]=[...($defaults[$source]??[]),...$patch,'url'=>$patch['url']??$defaults[$source]['url']??null];}
        return $defaults;
    }

    private function localize(array $task,array $images): array
    {
        $frame=static function(array $row) use($images): array {
            foreach([$row['originalUrl']??null,$row['fullUrl']??null,$row['url']??null] as $candidate){
                if(!is_string($candidate))continue;$patch=$images[$candidate]??$images[preg_replace('/[?#].*$/','',$candidate)]??null;if(!is_array($patch))continue;
                $result=[...$row,...$patch,'url'=>$patch['url']??$row['url']??null,'originalUrl'=>$row['originalUrl']??$candidate,'fullUrl'=>$patch['url']??$row['fullUrl']??$row['url']??null];
                foreach(['id','objectiveIds','association'] as $key){if(array_key_exists($key,$row))$result[$key]=$row[$key];}return $result;
            }return $row;
        };
        if(isset($task['gallery']))$task['gallery']=array_map($frame,$task['gallery']);
        foreach(['media','questContent'] as $key){if(isset($task[$key]['gallery']))$task[$key]['gallery']=array_map($frame,$task[$key]['gallery']);}
        foreach($task['objectives']??[] as $i=>$goal){if(isset($goal['media']))$task['objectives'][$i]['media']=array_map($frame,$goal['media']);}
        return $task;
    }

    public function item(array $data,string $id,string $mode): array
    {
        $reference=ContentDataset::where('path','guide/reference-'.$mode.'.json')->first();
        $matches=static function($row) use($id){
            if(!empty($row['cmsArchived']))return null;
            $found=false;$visit=function($value) use(&$visit,&$found,$id){if(is_array($value)){foreach($value as $child)$visit($child);}elseif($value===$id)$found=true;};$visit($row);return $found?$row:null;
        };
        $related=$reference?PublicJson::select($reference->json(),['meta'],['crafts'=>$matches,'barters'=>$matches,'traders'=>static fn($row)=>empty($row['cmsArchived'])?$row:null]):[];
        $used=[];$visit=function($value) use(&$visit,&$used){if(is_array($value)){foreach($value as $child)$visit($child);}elseif(is_string($value))$used[$value]=true;};$visit($data['items']);$visit($related['crafts']??[]);$visit($related['barters']??[]);
        $related['traders']=array_values(array_filter($related['traders']??[],fn($row)=>isset($used[$row['id']??''])));unset($related['hideout']);
        $questIds=DB::table('wiki_objective_items as i')->join('wiki_items as item','item.id','=','i.item_id')->join('wiki_objective_groups as g','g.id','=','i.group_id')->join('wiki_objectives as o','o.id','=','g.objective_id')->join('wiki_quests as q','q.id','=','o.quest_id')
            ->where('item.external_id',$id)->where('item.archived',0)->where('i.archived',0)->where('g.archived',0)->where('o.archived',0)->where('q.archived',0)->where('q.published',1)->where('q.mode',$mode)->distinct()->pluck('q.external_id');
        $tasks=Quest::where('published',1)->where('mode',$mode)->whereIn('quest_id',$questIds)->orderBy('id')->get()->map(fn($quest)=>PublicCatalog::quest($quest->payload))->all();
        $data['related']=['reference'=>array_intersect_key($related,array_flip(['meta','traders','hideout','crafts','barters'])),'quests'=>['cms'=>true,'tasks'=>$tasks,'questItems'=>[],'maps'=>[]]];
        return $data;
    }
}
