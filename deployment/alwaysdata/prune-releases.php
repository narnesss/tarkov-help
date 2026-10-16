<?php
declare(strict_types=1);

// Удаляем только старые каталоги кода с ожидаемым именем, не следуя симлинкам.
$base = realpath($argv[1] ?? '') ?: throw new RuntimeException('Нет каталога релизов.');
if(is_link($argv[1])||basename($base)!=='tarkov-releases'){throw new RuntimeException('Очистка разрешена только в настоящем каталоге tarkov-releases.');}
$keep = array_filter([realpath($argv[2] ?? ''), realpath($argv[3] ?? '')]);
$candidates = [];
foreach (new DirectoryIterator($base) as $entry) {
    if (!$entry->isLink() && $entry->isDir() && preg_match('/^[a-f0-9]{40}-[0-9]+-[0-9]+$/D', $entry->getFilename())) {
        $candidates[$entry->getPathname()] = $entry->getMTime();
    }
}
arsort($candidates);
$completed=array_filter($candidates,fn($time,$path)=>is_file($path.'/REVISION')&&!is_link($path.'/REVISION')&&filesize($path.'/REVISION')<128&&trim(file_get_contents($path.'/REVISION'))===basename($path),ARRAY_FILTER_USE_BOTH);
$keep = array_unique([...$keep, ...array_slice(array_keys($completed), 0, 3)]);
$remove = function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) { $remove($entry->getPathname()); }
    rmdir($path);
};
$deleted=0;foreach (array_keys($candidates) as $path) {
    if (!in_array($path, $keep, true)) { $remove($path);$deleted++; }
}
echo 'Удалено старых или незавершённых каталогов кода: '.$deleted.PHP_EOL;
