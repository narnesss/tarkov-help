<?php
declare(strict_types=1);
namespace App\Services;

/** Читает нужные поля проверенного JSON-файла, декодируя большие списки по одной записи. */
final class PublicJson
{
    public static function select(string $json,array $fields,array $collections=[]): array
    {
        $position=self::space($json,0);self::expect($json,$position,'{');$position++;$out=[];
        while(true){
            $position=self::space($json,$position);if(($json[$position]??'')==='}')return $out;
            self::expect($json,$position,'"');$end=self::end($json,$position);$key=json_decode(substr($json,$position,$end-$position),true,128,JSON_THROW_ON_ERROR);
            $position=self::space($json,$end);self::expect($json,$position,':');$position=self::space($json,$position+1);
            if(isset($collections[$key])){
                self::expect($json,$position,'[');$cursor=self::space($json,$position+1);$rows=[];
                while(($json[$cursor]??'')!==']'){
                    $rowEnd=self::end($json,$cursor);$row=json_decode(substr($json,$cursor,$rowEnd-$cursor),true,128,JSON_THROW_ON_ERROR);
                    $selected=$collections[$key]($row);if($selected!==null)$rows[]=$selected;
                    $cursor=self::space($json,$rowEnd);if(($json[$cursor]??'')===']')break;
                    self::expect($json,$cursor,',');$cursor=self::space($json,$cursor+1);
                }
                self::expect($json,$cursor,']');$end=$cursor+1;$out[$key]=$rows;
            }else{
                $end=self::end($json,$position);
                if(in_array($key,$fields,true))$out[$key]=json_decode(substr($json,$position,$end-$position),true,128,JSON_THROW_ON_ERROR);
            }
            $position=self::space($json,$end);if(($json[$position]??'')==='}')return $out;
            self::expect($json,$position,',');$position++;
        }
    }
    private static function space(string $json,int $position): int
    { return $position+strspn($json," \t\r\n",$position); }
    private static function expect(string $json,int $position,string $character): void
    { if(($json[$position]??'')!==$character)throw new \JsonException('Некорректная структура контента.'); }
    private static function end(string $json,int $start): int
    {
        $quoted=false;$depth=0;$length=strlen($json);
        for($i=$start;$i<$length;$i++){
            $ch=$json[$i];
            if($quoted){
                // Длинные подписи и описания пропускаются нативным сканером без цикла PHP по байтам.
                $i+=strcspn($json,"\"\\",$i);
                if($i >= $length)break;
                if($json[$i]==='\\'){$i++;continue;}
                $quoted=false;if($depth===0)return $i+1;continue;
            }
            if($ch==='"'){$quoted=true;continue;}
            if($ch==='{'||$ch==='['){$depth++;continue;}
            if($ch==='}'||$ch===']'){if($depth===0)return $i;if(--$depth===0)return $i+1;continue;}
            if($depth===0&&($ch===','||ctype_space($ch)))return $i;
        }
        throw new \JsonException('Незавершённая структура контента.');
    }
}
