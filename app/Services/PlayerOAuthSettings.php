<?php
declare(strict_types=1);
namespace App\Services;

/** Обновляет только реквизиты OAuth, не меняя ключ приложения и доступ к БД. */
final class PlayerOAuthSettings
{
    public static function write(string $environment,array $settings): void
    {
        $allowed=['GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET','DISCORD_CLIENT_ID','DISCORD_CLIENT_SECRET'];
        if(!$settings || array_diff(array_keys($settings),$allowed)){throw new \InvalidArgumentException('Недопустимые настройки OAuth.');}
        foreach(['GOOGLE','DISCORD'] as $provider){
            if(isset($settings[$provider.'_CLIENT_ID'])!==isset($settings[$provider.'_CLIENT_SECRET'])){throw new \InvalidArgumentException('Нужны оба реквизита провайдера.');}
        }
        foreach($settings as $value){if(!is_string($value)||!preg_match('/^[a-zA-Z0-9._-]{1,255}$/D',$value)){throw new \InvalidArgumentException('Недопустимый формат реквизитов OAuth.');}}
        $path=realpath($environment);if(!$path){throw new \RuntimeException('Файл окружения не найден.');}
        $contents=file_get_contents($path);$before=\Dotenv\Dotenv::parse($contents);
        foreach($settings as $key=>$value){
            $contents=preg_replace('/^'.preg_quote($key,'/').'\s*=.*(?:\r?\n|$)/m','',$contents);
            $contents=rtrim($contents)."\n".$key.'="'.$value.'"'."\n";
        }
        $after=\Dotenv\Dotenv::parse($contents);
        foreach($before as $key=>$value){if(!array_key_exists($key,$settings)&&($after[$key]??null)!==$value){throw new \RuntimeException('Изменилась посторонняя настройка.');}}
        $temporary=tempnam(dirname($path),'.oauth-');
        try{chmod($temporary,0600);if(file_put_contents($temporary,$contents,LOCK_EX)!==strlen($contents)||!rename($temporary,$path)){throw new \RuntimeException('Не удалось сохранить настройки.');}}
        finally{if(is_file($temporary)){unlink($temporary);}}
    }
}
