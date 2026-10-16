<?php

declare(strict_types=1);
namespace App\Services;

use App\Models\{ContentDataset,Quest};
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Одноразовые точечные обновления контента без замены редакторских данных и прогресса. */
final readonly class ContentUpdates
{
    public function apply(array $update,bool $dryRun=false): array
    {
        if(($update['schemaVersion']??null)!==1||!is_int($update['id']??null)||$update['id']<1){throw new RuntimeException('Некорректный пакет контента.');}
        $hash=hash('sha256',json_encode($update,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return app(WikiSync::class)->run(fn()=>DB::transaction(function()use($update,$hash,$dryRun): array {
            $key=['owner_type'=>'content-update','owner_id'=>$update['id']];
            $applied=DB::table('wiki_projection_states')->where($key)->value('content_ref');
            if($applied!==null){if(!hash_equals($applied,$hash)){throw new RuntimeException('Применённый пакет нельзя менять: создайте новый номер обновления.');}return ['applied'=>false,'quests'=>0,'datasets'=>0];}
            $quests=[];$datasets=[];
            foreach($update['quests']??[] as $patch){
                foreach($patch['modes'] as $mode){
                    $quest=Quest::where('quest_id',$patch['id'])->where('mode',$mode)->lockForUpdate()->firstOrFail();$payload=$quest->payload;
                    if(array_column($payload['objectives']??[],'id')!==$patch['expectedObjectiveIds']){throw new RuntimeException('Цели квеста изменились: '.$patch['id'].' / '.$mode);}
                    $payload['questContent']['walkthrough']=$patch['walkthrough'];
                    $payload['gallery']=$this->gallery($payload['gallery']??[],$patch['gallery']??[]);
                    foreach($payload['objectives'] as &$goal){$fields=$patch['objectiveGuides'][$goal['id']]??[];
                        foreach($fields as $field=>$value){if(!in_array($field,['description','maps'],true)){throw new RuntimeException('Недопустимое поле цели.');}$goal[$field]=$value;}}
                    unset($goal);$quests[]=[$quest,$payload];
                }
            }
            foreach($update['datasets']??[] as $path=>$patch){
                if(!preg_match('~^(guide/quest-(?:map-index|content)|raid/(?:pve/)?[a-z-]+)\.json$~',$path)){throw new RuntimeException('Недопустимый набор обновления.');}
                $dataset=ContentDataset::where('path',$path)->lockForUpdate()->firstOrFail();$data=$dataset->content();
                $pointIds=[];
                if(isset($patch['archivePoints'])||isset($patch['pointReviews'])){
                    $expected=preg_replace('~^raid/(?:pve/)?|\.json$~','',$path);
                    if(($data['mapId']??null)!==$expected){throw new RuntimeException('Набор принадлежит другой карте.');}
                    foreach($patch['archivePoints']??[] as $id){$found=false;foreach($data['pois'] as &$point){if($point['id']===$id){$point['cmsArchived']=true;$point['archiveReason']=$patch['reason'];$found=true;$pointIds[]=$id;}}unset($point);
                        if(!$found){throw new RuntimeException('Точка отсутствует: '.$id);}}
                    foreach($patch['pointReviews']??[] as $review){$found=false;foreach($data['pois'] as &$point){if($point['id']!==$review['id']){continue;}$found=true;
                        if(($point['position']??null)!=$review['expectedPosition']){throw new RuntimeException('Координаты точки уже изменились: '.$review['id']);}
                        $point['hiddenByDefault']=true;$point['sourceStatus']='unverified-coordinates';$point['reviewReason']=$review['reason'];$pointIds[]=$point['id'];
                    }unset($point);if(!$found){throw new RuntimeException('Проверяемая точка отсутствует: '.$review['id']);}}
                }elseif($path==='guide/quest-map-index.json'){
                    foreach($patch['sourceMaps']??[] as $id=>$map){$data['sourceMaps'][$id]=$map;}
                    foreach($patch['sourceQuests']??[] as $id=>$maps){foreach($maps as $map=>$points){$data['sourceQuests'][$id][$map]=$points;}}
                    foreach($patch['modes']??[] as $mode=>$records){foreach($records as $id=>$maps){
                        $existing=$data['modes'][$mode][$id]??[];
                        foreach($maps as $map){$existing=array_values(array_filter($existing,fn($old)=>($old['sourceKey']??null)!==($map['sourceKey']??null)||$old['renderer']!==$map['renderer']));$existing[]=$map;}
                        $data['modes'][$mode][$id]=$existing;
                    }}
                }elseif($path==='guide/quest-content.json'){
                    foreach($patch['quests']??[] as $id=>$record){$before=$data['quests'][$id]??[];$data['quests'][$id]=[...$before,...$record,'gallery'=>$this->gallery($before['gallery']??[],$record['gallery']??[])];}
                    foreach($patch['modes']??[] as $mode=>$records){foreach($records as $id=>$source){$data['modes'][$mode][$id]=$source;}}
                }else{throw new RuntimeException('Отсутствует операция набора.');}
                $datasets[]=[$dataset,$data,array_values(array_unique($pointIds)),(string)$dataset->getRawOriginal('payload')];
            }
            $result=['applied'=>!$dryRun,'quests'=>count($quests),'datasets'=>count($datasets)];if($dryRun){return $result;}
            if(!empty($update['repairPointScopes'])){$result['separatedPoints']=app(WikiImporter::class)->repairPointScopes();}
            foreach($quests as [$quest,$payload]){app(QuestEditor::class)->update($quest,['payload'=>$payload],$quest->revision());app(WikiImporter::class)->quest($quest);}
            foreach($datasets as [$dataset,$data,$pointIds,$previousRef]){$dataset->setContent($data);$dataset->save();
                if($pointIds!==[]){app(WikiImporter::class)->changedPoints($dataset,$pointIds,$previousRef);}else{app(WikiImporter::class)->dataset($dataset);}}
            DB::table('wiki_projection_states')->insert([...$key,'content_ref'=>$hash]);
            return $result;
        }));
    }

    private function gallery(array $before,array $added): array
    {
        $known=[];$result=[];
        foreach([...$before,...$added] as $frame){$key=$frame['originalUrl']??$frame['fullUrl']??$frame['url']??'';if($key!==''&&!isset($known[$key])){$known[$key]=true;$result[]=$frame;}}
        return $result;
    }
}
