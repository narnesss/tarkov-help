<?php
declare(strict_types=1);
namespace App\Services;

/** Строит публичные выходы и условия доступа без изменения координат и связей источников. */
final class DocumentMapOverlay
{
    public const ALIASES=['groundzero'=>'ground-zero','streets'=>'streets-of-tarkov','lab'=>'the-lab','labyrinth'=>'the-labyrinth'];

    public function build(array $map,array $spawns,array $definition,array $raid,array $relations,array $catalog=[]): array
    {
        $id=$map['id'];$access=[];$byId=array_column($catalog,null,'id');
        foreach($spawns as $spawn){
            if(($spawn['map']??null)!==$id||!empty($spawn['cmsArchived']))continue;
            $access[$spawn['id']]=$this->access($spawn,$relations[$spawn['id']]??null,$catalog);
            $access[$spawn['id']]['keys']=array_map(static fn($key)=>$byId[$key['id']??'']??$key,$access[$spawn['id']]['keys']);
        }
        $exits=[];
        // Проверка владельца обязательна: один набор нельзя отрисовать на чужой карте.
        if(($raid['mapId']??null)===($definition['id']??null))foreach($raid['pois']??[] as $point){
            if(!empty($point['cmsArchived'])||!empty($point['hiddenByDefault'])||($point['sourceStatus']??null)==='unverified-coordinates'
                ||($point['kind']??null)!=='extract'||!in_array($point['category']??null,['extract-pmc','extract-shared'],true))continue;
            $position=$this->position($id,$definition,$point['position']??[]);
            if($position===null)continue;
            $floor=$this->floor($id,$definition,$point);
            $exits[(string)$point['id']]=['id'=>(string)$point['id'],'name'=>(string)($point['name']??'Выход ЧВК'),
                ...$position,'floor'=>$floor,'category'=>$point['category'],
                'conditions'=>array_values(array_filter($point['conditions']??[],is_string(...)))];
        }
        return ['mapId'=>$id,'exits'=>array_values($exits),'access'=>$access];
    }

    public function access(array $spawn,?array $relations=null,array $catalog=[]): array
    {
        $links=$relations??$spawn['cmsKeyUsages']??null;
        if(is_array($links)){
            $bypass=collect($links)->contains(fn($row)=>($row['usage_type']??'')==='bypass');
            $keys=[];foreach($links as $row){if(($row['usage_type']??'unlock')==='unlock')$keys[]=['id'=>$row['item']??null,'name'=>$row['name']??'Ключ'];}
            return ['requiresKey'=>!$bypass&&$keys!==[],'keys'=>$keys,'source'=>'relations'];
        }
        if(!empty($spawn['keyIds']))return ['requiresKey'=>true,'keys'=>array_map(fn($key)=>['id'=>$key,'name'=>'Ключ'],$spawn['keyIds']),'source'=>'content'];
        $caption=(string)($spawn['caption']??'');
        // Ориентир рядом с дверью, кодовая панель и простреливаемый замок не требуют ключа.
        $excluded=preg_match('/рядом с (?:заперт|закрыт)|ближайш|кодовый|кодовой|выстрел|через окно/iu',$caption);
        $required=!$excluded&&preg_match('/(?:заперт\p{L}*|закрыт\p{L}*|нужен|требуется|необходим\p{L}*|под\s+ключом).{0,180}(?:ключ|карт)|под\s+ключом/iu',$caption);
        $names=[];if($required&&preg_match_all('/«([^»]+)»/u',$caption,$matches))$names=$matches[1];
        if($required){
            if($names===[]&&preg_match('/(?:РБ|RB)-[\p{L}\d]+/ui',$caption,$rb))$names=['Ключ '.$rb[0]];
            if($names===[]&&preg_match('/(?:нужен|требуется|необходим\p{L}*)\s+(.+)|под\s+(ключом.+)/ui',$caption,$match)){
                $name=trim($match[1]?:($match[2]??'')," .,)\t\n");$names=[preg_replace('/^ключом\b/ui','Ключ',$name)];
            }
            $normalize=static fn(string $s)=>trim(preg_replace('/[^\p{L}\d]+/u',' ',str_replace(['ё','labs'],['е',''],mb_strtolower(preg_replace('/ключом|ключа|ключу/iu','ключ',$s)))));
            $text=$normalize($caption);$found=[];
            foreach($catalog as $key){$name=$normalize($key['name']);if(mb_strlen($name)>=12&&str_contains($text,$name))$found[$key['id']]=$key;}
            $keys=[];foreach($names as $name){$n=$normalize($name);$n=['ключ от завода'=>'ключ от выхода с завода'][$n]??$n;$matches=array_filter($catalog,static fn($key)=>$normalize($key['name'])===$n||(mb_strlen($n)>=6&&str_contains(' '.$normalize($key['name']).' ',' '.$n.' ')));
                if(count($matches)===1){$key=array_values($matches)[0];$found[$key['id']]=$key;}elseif($found===[]){$keys[]=['id'=>null,'name'=>preg_match('/ключ|карт/iu',$name)?$name:(str_contains(mb_strtolower($caption),'ключ-карт')?'Ключ-карта «'.$name.'»':'Ключ «'.$name.'»')];}}
            return ['requiresKey'=>true,'keys'=>$found!==[]?array_values($found):$keys,'source'=>'description'];
        }
        return ['requiresKey'=>false,'keys'=>[],'source'=>'description'];
    }

    private function position(string $id,array $definition,array $p): ?array
    {
        if(!isset($p['x'],$p['y'],$p['z'])||!is_numeric($p['x'])||!is_numeric($p['y'])||!is_numeric($p['z'])
            ||!is_finite((float)$p['x'])||!is_finite((float)$p['y'])||!is_finite((float)$p['z']))return null;
        $calibration=config('document-overlays.'.$id);
        if($calibration){
            [$a,$b,$c,$d,$e,$f]=$calibration['matrix'];
            $x=($a*$p['x']+$b*$p['z']+$c)/$calibration['width']*100;
            $y=($d*$p['x']+$e*$p['z']+$f)/$calibration['height']*100;
        }else{
            if(($definition['baseAsset']['type']??null)!=='svg'||empty($definition['bounds']))return null;
            $bounds=$definition['svgBounds']??$definition['bounds'];
            $r=deg2rad((float)($definition['coordinateRotation']??0));
            $rotate=static fn($x,$z)=>[$x*cos($r)-$z*sin($r),-($x*sin($r)+$z*cos($r))];
            $corners=[$rotate($bounds[0][0],$bounds[0][1]),$rotate($bounds[1][0],$bounds[1][1])];
            [$px,$py]=$rotate($p['x'],$p['z']);$xmin=min($corners[0][0],$corners[1][0]);$ymin=min($corners[0][1],$corners[1][1]);
            $dx=abs($corners[0][0]-$corners[1][0]);$dy=abs($corners[0][1]-$corners[1][1]);if($dx<.0001||$dy<.0001)return null;
            $x=($px-$xmin)/$dx*100;$y=($py-$ymin)/$dy*100;
        }
        return $x>=0&&$x<=100&&$y>=0&&$y<=100?['x'=>$x,'y'=>$y]:null;
    }

    private function floor(string $id,array $definition,array $point): string
    {
        if(in_array($id,['icebreaker','labyrinth'],true))return 'floor-1';
        $p=$point['position'];$selected=$definition['baseFloor']['id']??'';
        foreach($definition['floors']??[] as $floor)foreach($floor['extents']??[] as $extent){
            [$low,$high]=$extent['height']??[-INF,INF];if($p['y']<$low||$p['y']>=$high)continue;
            if(!empty($extent['bounds'])){
                $inside=false;foreach($extent['bounds'] as $box){if($p['x']>=min($box[0][0],$box[1][0])&&$p['x']<=max($box[0][0],$box[1][0])&&$p['z']>=min($box[0][1],$box[1][1])&&$p['z']<=max($box[0][1],$box[1][1]))$inside=true;}
                if(!$inside)continue;
            }$selected=$floor['id'];
        }
        return preg_match('/underground|technical|basement/iu',$selected)?'floor--1':(preg_match('/second/iu',$selected)?'floor-2':(preg_match('/third/iu',$selected)?'floor-3':'floor-1'));
    }
}
