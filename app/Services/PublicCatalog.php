<?php
declare(strict_types=1);
namespace App\Services;

/** Облегчённые публичные представления; исходные файлы и стабильные ID не изменяются. */
final class PublicCatalog
{
    private static function fields(array $value,array $keys): array
    { return array_intersect_key($value,array_flip($keys)); }
    private static function reference(mixed $value): mixed
    { return is_array($value)?self::fields($value,['id','name','normalizedName','imageLink']):$value; }
    public static function quest(array $task): array
    {
        $task=self::fields($task,['id','name','normalizedName','trader','map','experience','minPlayerLevel','factionName','kappaRequired','lightkeeperRequired',
            'story','storyBranches','taskRequirements','traderRequirements','otherRequirements','requiredPrestige','availableDelaySecondsMin','storyRequirements',
            'catalogRequirements','catalogLevelUnknown','catalogModeNotes','catalogUnavailableModes','catalogSection','catalogKind','catalogGame','failConditions','objectives','finishRewards']);
        foreach(['trader','map'] as $key){if(isset($task[$key])){$task[$key]=self::reference($task[$key]);}}
        $task['objectives']=array_map(static function(array $goal): array {
            $goal=self::fields($goal,['id','type','description','maps','zones','modes','count','items','item','questItem','foundInRaid']);
            foreach(['items','maps'] as $key){if(isset($goal[$key])){$goal[$key]=array_map(self::reference(...),$goal[$key]);}}
            foreach(['item','questItem'] as $key){if(isset($goal[$key])){$goal[$key]=self::reference($goal[$key]);}}
            if(isset($goal['zones'])){$goal['zones']=array_map(static fn($zone)=>['map'=>self::reference($zone['map']??null)],$goal['zones']);}
            return $goal;
        },$task['objectives']??[]);
        if(isset($task['finishRewards'])){$task['finishRewards']=['items'=>array_map(static fn($row)=>['item'=>self::reference($row['item']??null),'count'=>$row['count']??1],$task['finishRewards']['items']??[])];}
        $task['_detailPending']=true;return $task;
    }
    public static function dataset(array $data,string $path,string $view,?string $id): array
    {
        if(str_contains($path,'/items-')){
            $data['catalog']=$view==='catalog';
            if($view==='detail'){$data['items']=array_values(array_filter($data['items']??[],fn($item)=>($item['id']??null)===$id));abort_if(!$data['items'],404);}
            else{$data['items']=array_map(static function($item){
                $row=self::fields($item,['id','name','shortName','description','iconLink','types','width','height','marketTraderPrice']);
                $row['marketTraderPrice']??=max([0,...array_map(fn($price)=>(float)($price['priceRUB']??0),$item['sellToTrader']??$item['sellFor']??[])])?:null;
                $row['properties']=self::fields($item['properties']??[],['propertiesType','caliber','damage','penetrationPower','armorDamage','initialSpeed']);return $row;
            },$data['items']??[]);}
        }elseif(str_contains($path,'/reference-')){
            unset($data['mapMetadata']);$data['maps']=array_map(fn($map)=>self::fields($map,['id','name','normalizedName','bosses']),$data['maps']??[]);
        }
        return $data;
    }
    public static function questGlobals(array $data,string $view): array
    {
        $data['traders']=array_map(self::reference(...),array_values($data['traders']??[]));
        if($view==='catalog'){$data=self::fields($data,['meta','maps','traders','questItems','catalogSummary','storyCount','wikiCoverage','cms']);$data['catalog']=true;}
        if($view==='lookup'){$data=self::fields($data,['meta','maps','questItems','cms']);$data['lookup']=true;}
        return $data;
    }
    public static function detailItems(array $data,array $task): array
    {
        $ids=[];$visit=function($value) use(&$visit,&$ids){if(is_array($value)){foreach($value as $child){$visit($child);}}elseif(is_string($value)){$ids[$value]=true;}};$visit($task);
        $data['items']=array_values(array_filter($data['items']??[],fn($item)=>isset($ids[$item['id']??''])));return $data;
    }
}
