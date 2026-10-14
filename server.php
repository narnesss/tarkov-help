<?php
declare(strict_types=1);

/** Маршрутизатор artisan serve: HTML-алиасы тоже должны исполняться через Laravel. */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$alias = preg_match('~^/(?:index|documents|raid|quest|quests|reference|boss|quest-map-source)(?:\.(?:html|php))?/?$~D', $path);
if (!$alias && !str_contains($path, '..') && is_file(__DIR__.'/public'.$path) && !str_ends_with($path, '.php')) {
    return false;
}
require __DIR__.'/public/index.php';
