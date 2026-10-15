<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

$settings=json_decode(file_get_contents($argv[1]??''),true,flags:JSON_THROW_ON_ERROR);
\App\Services\PlayerOAuthSettings::write(dirname(__DIR__,2).'/.env',$settings);
echo "Настройки OAuth сохранены; APP_KEY и остальные настройки не изменены.\n";
