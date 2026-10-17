<?php

declare(strict_types=1);
namespace App\Services;

/** Подставляет локальные копии только в поля изображений публичного ответа, не переписывая контент. */
final class PublicMedia
{
    public static function revision(): string
    { return hash('sha256',serialize(config('public-media.aliases',[]))); }

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
        return $json;
    }
}
