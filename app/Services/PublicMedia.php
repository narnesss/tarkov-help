<?php

declare(strict_types=1);
namespace App\Services;

/** Подставляет локальные копии только в поля изображений публичного ответа, не переписывая контент. */
final class PublicMedia
{
    public static function revision(): string
    { return hash('sha256',serialize([config('public-media.aliases',[]),config('public-media.previews',[])])); }

    public static function json(string $json): string
    {
        // Замена JSON-токенов не требует распаковки полного каталога в дополнительный PHP-массив.
        foreach(config('public-media.aliases',[]) as $source=>$asset){
            foreach([0,JSON_UNESCAPED_SLASHES] as $flags){
                $old=json_encode($source,$flags|JSON_THROW_ON_ERROR);
                if(!str_contains($json,$old))continue;
                $new=json_encode($asset,$flags|JSON_THROW_ON_ERROR);
                $pattern='~("(?:iconLink|imageLink|image512pxLink|gridImageLink|baseImageLink|inspectImageLink|image8xLink|icon)"\s*:\s*)'.preg_quote($old,'~').'~';
                $json=preg_replace_callback($pattern,static fn($match)=>$match[1].$new,$json)
                    ??throw new \RuntimeException('Не удалось подготовить публичные изображения.');
            }
        }
        // Один проход по полям изображений: размер каталога не умножается на число превью.
        $previews=config('public-media.previews',[]);
        if($previews){
            $json=preg_replace_callback('~("(?:url|iconLink|imageLink|image512pxLink|gridImageLink|baseImageLink|inspectImageLink|image8xLink|icon)"\\s*:\\s*)("(?:[^"\\\\]|\\\\.)*")~',static function(array $match) use($previews): string {
                $source=json_decode($match[2],true,flags:JSON_THROW_ON_ERROR);
                if(!is_string($source))return $match[0];
                $parts=parse_url($source);
                if(($parts['scheme']??'')!=='https'||($parts['host']??'')!=='static.wikia.nocookie.net'
                    ||isset($parts['user'])||isset($parts['pass'])||isset($parts['port']))return $match[0];
                $path=$parts['path']??'';
                if(!preg_match('~^(/escapefromtarkov_gamepedia/images/[^/]+/[^/]+/[^/]+/revision/latest)/scale-to-width-down/[1-9][0-9]*$~D',$path,$canonical))return $match[0];
                $asset=$previews['https://static.wikia.nocookie.net'.$canonical[1].(isset($parts['query'])?'?'.$parts['query']: '')]??null;
                return $asset===null?$match[0]:$match[1].json_encode($asset,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            },$json)??throw new \RuntimeException('Не удалось подготовить превью.');
        }
        return $json;
    }
}
