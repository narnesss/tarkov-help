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
$matches = fn(string $path,string $name): bool => is_file($path.'/'.$name) && !is_link($path.'/'.$name)
    && filesize($path.'/'.$name)<128 && trim(file_get_contents($path.'/'.$name))===basename($path);
$completed=array_filter($candidates,function($time,$path)use($matches){
    if(!$matches($path,'REVISION'))return false;
    if(file_exists($path.'/.publication-pending')||file_exists($path.'/PUBLISHED'))return $matches($path,'PUBLISHED');
    // До введения PUBLISHED успешный optimize оставлял config.php; один REVISION не означает успех.
    return is_file($path.'/bootstrap/cache/config.php')&&!is_link($path.'/bootstrap/cache/config.php');
},ARRAY_FILTER_USE_BOTH);
$keep = array_unique([...$keep, ...array_slice(array_keys($completed), 0, 3)]);
$remove = function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) { $remove($entry->getPathname()); }
    rmdir($path);
};
$incoming=dirname($base).'/tarkov-incoming';
$safeIncoming=is_dir($incoming)&&!is_link($incoming)&&realpath($incoming)===$incoming;
$deleted=0;foreach (array_keys($candidates) as $path) {
    if (!in_array($path, $keep, true)) {
        $remove($path);$deleted++;
        // Удаляем только архив того же удалённого релиза, без перехода по ссылкам.
        $archive=$incoming.'/'.basename($path).'.tar.gz';
        if($safeIncoming&&is_file($archive)&&!is_link($archive))unlink($archive);
    }
}
echo 'Удалено старых или незавершённых каталогов кода: '.$deleted.PHP_EOL;
