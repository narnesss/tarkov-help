<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
try {
    EftWiki\Factory::services(dirname(__DIR__))['app']->handle(EftWiki\Http\Request::globals())->send();
} catch (Throwable $error) {
    error_log('[EftWiki bootstrap] ' . $error::class . ': ' . $error->getMessage());
    EftWiki\Http\Response::json(['error' => 'Не удалось открыть хранилище сайта. Сохранённые данные не удалены.'], 503)->send();
}
