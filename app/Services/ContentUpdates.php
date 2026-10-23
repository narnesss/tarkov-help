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
        // Независимые исправления проекции возобновляются пакетами; файлы контента здесь не меняются.
        $separated=0;
        if(!$dryRun&&!empty($update['repairPointScopes'])&&!DB::table('wiki_projection_states')->where(['owner_type'=>'content-update','owner_id'=>$update['id']])->exists()){
            $separated=app(WikiImporter::class)->repairPointScopes();
        }
        return app(WikiSync::class)->run(fn()=>DB::transaction(function()use($update,$hash,$dryRun,$separated): array {
            $key=['owner_type'=>'content-update','owner_id'=>$update['id']];
            $applied=DB::table('wiki_projection_states')->where($key)->value('content_ref');
            if($applied!==null){if(!hash_equals($applied,$hash)){throw new RuntimeException('Применённый пакет нельзя менять: создайте новый номер обновления.');}return ['applied'=>false,'quests'=>0,'datasets'=>0];}
            $quests=[];$datasets=[];$catalogChanged=false;
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
                if(!preg_match('~^(spawns|maps|source|guide/(?:quest-(?:map-index|content)|(?:quests|items)-(?:pve|regular))|raid/(?:pve/)?[a-z-]+)\.json$~',$path)){throw new RuntimeException('Недопустимый набор обновления.');}
                $dataset=ContentDataset::where('path',$path)->lockForUpdate()->firstOrFail();$data=$dataset->content();
                $pointIds=[];$addedReferences=[];
                if($path==='raid/maps.json'&&isset($patch['records'])){
                    $data=$this->records($data,$patch['records'],$path);
                }elseif(isset($patch['raidPointRecords'])){
                    $expected=preg_replace('~^raid/(?:pve/)?|\.json$~','',$path);
                    if(($data['mapId']??null)!==$expected){throw new RuntimeException('Набор принадлежит другой карте.');}
                    $before=$data['pois'];$data['pois']=$this->records($before,$patch['raidPointRecords'],'raid-points');
                    $data['pois']=app(WikiSync::class)->reconcileDataset($before,$data['pois']);
                    $pointIds=array_column($patch['raidPointRecords'],'id');
                }elseif(in_array($path,['spawns.json','maps.json'],true)){
                    $data=$this->records($data,$patch['records']??[],$path);
                    if($path==='spawns.json'){$pointIds=array_column($patch['records'],'id');}
                }elseif($path==='source.json'){
                    foreach($patch['metadata']??[] as $field=>$value){
                        if(!in_array($field,['repository','commit','importedAt','spawnCount','mapCount','retiredPointIds','authors','license'],true)){throw new RuntimeException('Недопустимое поле источника.');}
                        $data[$field]=$value;
                    }
                }elseif(isset($patch['archivePoints'])||isset($patch['pointReviews'])||isset($patch['pointChanges'])){
                    $expected=preg_replace('~^raid/(?:pve/)?|\.json$~','',$path);
                    if(($data['mapId']??null)!==$expected){throw new RuntimeException('Набор принадлежит другой карте.');}
                    foreach($patch['pointChanges']??[] as $change){$found=false;
                        if(empty($change['expected'])||array_diff(array_keys($change['fields']??[]),['name','cmsArchived','archiveReason','hiddenByDefault','sourceStatus','floorOverride'])){throw new RuntimeException('Недопустимые поля исправления точки.');}
                        foreach($data['pois'] as &$point){if($point['id']!==$change['id']){continue;}$found=true;
                            foreach($change['expected'] as $field=>$value){if(($point[$field]??null)!=$value){throw new RuntimeException('Точка уже изменена: '.$change['id']);}}
                            $point=[...$point,...$change['fields']];$pointIds[]=$point['id'];
                        }unset($point);if(!$found){throw new RuntimeException('Исправляемая точка отсутствует: '.$change['id']);}
                    }
                    foreach($patch['archivePoints']??[] as $id){$found=false;foreach($data['pois'] as &$point){if($point['id']===$id){$point['cmsArchived']=true;$point['archiveReason']=$patch['reason'];$found=true;$pointIds[]=$id;}}unset($point);
                        if(!$found){throw new RuntimeException('Точка отсутствует: '.$id);}}
                    foreach($patch['pointReviews']??[] as $review){$found=false;foreach($data['pois'] as &$point){if($point['id']!==$review['id']){continue;}$found=true;
                        if(($point['position']??null)!=$review['expectedPosition']){throw new RuntimeException('Координаты точки уже изменились: '.$review['id']);}
                        $point['hiddenByDefault']=true;$point['sourceStatus']='unverified-coordinates';$point['reviewReason']=$review['reason'];$pointIds[]=$point['id'];
                    }unset($point);if(!$found){throw new RuntimeException('Проверяемая точка отсутствует: '.$review['id']);}}
                }elseif(preg_match('~^guide/(?:quests|items)-(?:pve|regular)\.json$~',$path)){
                    $data=$this->appendReferences($data,$patch['appendReferences']??[],$addedReferences);$catalogChanged=true;
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
                $previousRef=(string)$dataset->getRawOriginal('payload');
                // Не держим несколько полных каталогов в памяти одновременно.
                if(!$dryRun){$dataset->setContent($data);}
                $datasets[]=[$dataset,array_values(array_unique($pointIds)),$previousRef,$addedReferences,isset($patch['appendReferences'])];unset($data);
            }
            $result=['applied'=>!$dryRun,'quests'=>count($quests),'datasets'=>count($datasets)];if($dryRun){return $result;}
            if(!empty($update['repairPointScopes'])){$result['separatedPoints']=$separated;}
            foreach($datasets as [$dataset,$pointIds,$previousRef,$addedReferences,$additive]){
                if($additive){
                    // Небольшое дополнение не запускает полный импорт и переиндексацию справочника.
                    ContentDataset::withoutEvents(fn()=>$dataset->save());
                    app(ReferenceIndexer::class)->appendItems($dataset,$addedReferences);
                    app(WikiImporter::class)->appendItems($dataset,$addedReferences,$previousRef);
                }else{$dataset->save();
                    if($pointIds!==[]){app(WikiImporter::class)->changedPoints($dataset,$pointIds,$previousRef);}else{app(WikiImporter::class)->dataset($dataset);}
                }
            }
            // Сначала добавляем справочные предметы, затем связываем с ними новые цели и награды.
            foreach($quests as [$quest,$payload]){app(QuestEditor::class)->update($quest,['payload'=>$payload],$quest->revision());app(WikiImporter::class)->quest($quest,$catalogChanged);}
            DB::table('wiki_projection_states')->insert([...$key,'content_ref'=>$hash]);
            return $result;
        }));
    }

    /** Добавляет отсутствующие предметы; существующие ID и редакторские значения не заменяет. */
    private function appendReferences(array $data,array $additions,array &$added): array
    {
        foreach($additions as $field=>$records){
            if(!in_array($field,['items','questItems'],true)||!is_array($records)){throw new RuntimeException('Недопустимый справочник дополнения.');}
            $data[$field]??=[];$known=[];
            foreach($data[$field] as $record){if(is_array($record)&&isset($record['id'])){$known[$record['id']]=true;}}
            $dictionary=$field==='items'&&!array_is_list($data[$field]);
            foreach($records as $record){
                if(!is_array($record)||!is_string($record['id']??null)||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}$/D',$record['id'])||!is_string($record['name']??null)||trim($record['name'])===''){
                    throw new RuntimeException('Некорректный предмет дополнения.');
                }
                if(isset($known[$record['id']])){continue;}
                if($dictionary){$key=$record['id'];$data[$field][$key]=$record;}else{$data[$field][]=$record;$key=array_key_last($data[$field]);}
                $added[]=['record'=>$record,'pointer'=>'/'.$field.'/'.str_replace(['~','/'],['~0','~1'],(string)$key)];
                $known[$record['id']]=true;
            }
        }
        return $data;
    }

    /** Проверяет прежние значения до записи; ID, порядок и дополнительные связи редактора сохраняются. */
    private function records(array $data,array $patches,string $path): array
    {
        $indexes=[];foreach($data as $i=>$row){if(isset($indexes[$row['id']])){throw new RuntimeException('Повтор ID в наборе.');}$indexes[$row['id']]=$i;}
        $allowed=$path==='maps.json'?['file','width','height','type','coordinateSource','coordinateDate','documentPanels','layeredFile']:
            ['map','mapName','doc','docName','caption','floor','x','y','x2','y2','images','originalImages','cmsArchived','cmsPositions','cmsMedia','cmsDocuments','archiveReason','sourcePointId','coordinateSource','coordinateDate','sourceCoordinates'];
        if($path==='raid/maps.json'){$allowed=['transform','bounds','svgBounds','heightRange','floors','coordinateSource','floorPolicy'];}
        if($path==='raid-points'){$allowed=['position','name','description','category','kind','lootType','containerId','itemIds','itemNames','keyIds','keyNames','sourceId','sourceZone','faction','hazardType','weaponId','bossId','questId','objectiveId','outline','targetMapId','gameMode','sourceMapId','sourceStatus','sourceNote','coordinateSource','coordinateDate','cmsArchived','archiveReason','hiddenByDefault'];}
        $seen=[];
        foreach($patches as $patch){
            $id=$patch['id'];if(isset($seen[$id])){throw new RuntimeException('Повтор ID в обновлении.');}$seen[$id]=true;
            $index=$indexes[$id]??null;$expected=$patch['expected'];
            foreach($patch['fields'] as $field=>$value){if(!in_array($field,$allowed,true)){throw new RuntimeException('Недопустимое поле записи: '.$field);}}
            // Новый сервер может импортировать уже актуальный seed до первого применения пакета.
            $current=$index===null?null:$data[$index];$ready=$current!==null;
            foreach($patch['fields'] as $field=>$value){
                if(($current[$field]??null)==$value){continue;}
                // Точные координаты того же источника уже могут иметь более свежую дату сверки.
                if($path==='raid-points'&&$field==='coordinateDate'&&isset($patch['fields']['position'],$patch['fields']['coordinateSource'])
                    &&($current['coordinateSource']??null)===$patch['fields']['coordinateSource']&&$this->laterCoordinateDate($current[$field]??null,$value)){continue;}
                $ready=false;break;
            }
            if($ready){continue;}
            if(($index===null)!==($expected===null)){throw new RuntimeException('Изменился состав точек: '.$id);}
            foreach($expected??[] as $field=>$value){if(($data[$index][$field]??null)!=$value){throw new RuntimeException('Запись уже изменена редактором: '.$id.' / '.$field);}}
            if($index===null){$data[]=['id'=>$id,...$patch['fields']];$indexes[$id]=array_key_last($data);}
            else{$data[$index]=[...$data[$index],...$patch['fields']];}
        }
        return $data;
    }

    private function laterCoordinateDate(mixed $current,mixed $published): bool
    {
        foreach([$current,$published] as $date){
            if(!is_string($date)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$parts)||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])){return false;}
        }
        return $current>$published;
    }

    private function gallery(array $before,array $added): array
    {
        $known=[];$result=[];
        foreach([...$before,...$added] as $frame){$key=$frame['originalUrl']??$frame['fullUrl']??$frame['url']??'';if($key!==''&&!isset($known[$key])){$known[$key]=true;$result[]=$frame;}}
        return $result;
    }
}
