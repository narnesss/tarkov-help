<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{ContentDataset,Quest};
use Illuminate\Support\Facades\DB;

/** Добавляет проверенные иллюстрации и схемы, не заменяя цели и редакторский контент. */
final class QuestGuideUpdate
{
    public function apply(array $patch): void
    {
        app(WikiSync::class)->run(fn()=>DB::transaction(function() use($patch): void {
            $version=$patch['version'];
            foreach(Quest::whereIn('quest_id',array_keys($patch['quests']))->lockForUpdate()->get() as $quest){
                $addition=$patch['quests'][$quest->quest_id];$payload=$quest->payload;
                if(!empty($payload['guideUpdates'][$version])){continue;}
                $ids=array_column($payload['objectives']??[],'id');
                if(array_diff($addition['expectedObjectiveIds'],$ids)!==[]){
                    throw new \RuntimeException('Набор целей изменился: '.$quest->quest_id.' · '.$quest->mode);
                }
                foreach(['gallery','guideAdditions','guideSources','externalMaps','galleryArchiveRules'] as $field){
                    $payload[$field]=self::append($payload[$field]??[],$addition[$field]??[],$field==='gallery'?'id':(in_array($field,['guideAdditions','galleryArchiveRules'],true)?'id':'url'));
                }
                $payload['guideUpdates'][$version]=true;$quest->payload=$payload;$quest->save();
                // Изменены только дополнительные материалы; существующую предметную проекцию не переимпортируем.
                DB::table('wiki_projection_states')->where(['owner_type'=>'quest','owner_id'=>$quest->id])->update(['content_ref'=>$quest->getRawOriginal('payload')]);
            }
            $dataset=ContentDataset::where('path','guide/quest-map-index.json')->lockForUpdate()->first();
            if($dataset===null){return;}
            $index=$dataset->content();if(!empty($index['guideUpdates'][$version])){return;}
            foreach($patch['sourceMaps'] as $key=>$asset){if(isset($index['sourceMaps'][$key])&&$index['sourceMaps'][$key]!==$asset){throw new \RuntimeException('Конфликт схемы: '.$key);}$index['sourceMaps'][$key]=$asset;}
            foreach($patch['quests'] as $id=>$addition){
                foreach($addition['maps']??[] as $map){
                    $key=$map['sourceKey'];
                    if(isset($index['sourceQuests'][$id][$key])&&$index['sourceQuests'][$id][$key]!==$map['points']){throw new \RuntimeException('Конфликт точек: '.$id);}
                    $index['sourceQuests'][$id][$key]=$map['points'];
                    foreach(['pve','regular'] as $mode){$index['modes'][$mode][$id]=self::append($index['modes'][$mode][$id]??[],[$map],'sourceKey');}
                }
            }
            $index['guideUpdates'][$version]=true;$dataset->setContent($index);$dataset->save();
        }));
    }

    /** При повторном запуске и совпадении ключей сохраняет уже отредактированную запись. */
    private static function append(array $before,array $additions,string $key): array
    {
        $seen=[];foreach($before as $row){if(isset($row[$key])){$seen[(string)$row[$key]]=true;}}
        foreach($additions as $row){$value=(string)$row[$key];if(!isset($seen[$value])){$before[]=$row;$seen[$value]=true;}}
        return $before;
    }
}
