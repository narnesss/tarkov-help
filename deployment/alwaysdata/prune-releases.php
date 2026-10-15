<?php
declare(strict_types=1);

// Удаляем только старые каталоги кода с ожидаемым именем, не следуя симлинкам.
$base = realpath($argv[1] ?? '') ?: throw new RuntimeException('Нет каталога релизов.');
$keep = array_filter([realpath($argv[2] ?? ''), realpath($argv[3] ?? '')]);
$candidates = [];
foreach (new DirectoryIterator($base) as $entry) {
    if (!$entry->isLink() && $entry->isDir() && preg_match('/^[a-f0-9]{40}-[0-9]+-[0-9]+$/D', $entry->getFilename())) {
        $candidates[$entry->getPathname()] = $entry->getMTime();
    }
}
arsort($candidates);
$keep = array_unique([...$keep, ...array_slice(array_keys($candidates), 0, 3)]);
$remove = function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) { $remove($entry->getPathname()); }
    rmdir($path);
};
foreach (array_keys($candidates) as $path) {
    if (!in_array($path, $keep, true)) { $remove($path); }
}
