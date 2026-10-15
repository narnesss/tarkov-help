<?php
declare(strict_types=1);
namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/** Согласованные SQLite-снимки с общей блокировкой и ограничением хранения. */
final class SqliteBackups
{
    public function __construct(
        private readonly string $database,
        private readonly int $dailyKeep = 7,
        private readonly int $deployKeep = 3,
        private readonly int $maxBytes = 104857600,
    ) {
        if (!str_starts_with($database, '/') || !is_file($database) || is_link($database)) {
            throw new RuntimeException('Нужен существующий обычный файл SQLite по абсолютному пути.');
        }
        if ($dailyKeep < 1 || $deployKeep < 1 || $maxBytes < 1) {
            throw new RuntimeException('Лимиты хранения резервных копий должны быть положительными.');
        }
    }

    /** @return array{path: string, created: bool, deleted: int, count: int, bytes: int} */
    public function create(string $kind = 'daily'): array
    {
        if (!in_array($kind, ['daily', 'deploy'], true)) { throw new RuntimeException('Неизвестный вид резервной копии.'); }
        $lockPath = $this->database.'.backup.lock';
        if (is_link($lockPath)) { throw new RuntimeException('Файл блокировки не должен быть ссылкой.'); }
        $mask = umask(0077);
        try { $lock = fopen($lockPath, 'c'); } finally { umask($mask); }
        if ($lock === false) { throw new RuntimeException('Не удалось открыть блокировку бэкапа.'); }
        try {
            $deadline = microtime(true) + 60;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) { throw new RuntimeException('Другой процесс ещё создаёт бэкап.'); }
                usleep(100000);
            }
            $files = $this->copies();
            if ($kind === 'daily') {
                foreach ($files as $file) {
                    if ($file['kind'] === 'daily' && str_starts_with($file['stamp'], gmdate('Ymd'))) {
                        try { $this->verify($file['path']); } catch (Throwable) { continue; }
                        if ($file['bytes'] <= $this->maxBytes) { return $this->prune($file['path'], false); }
                    }
                }
            }
            $pdo = new PDO('sqlite:'.$this->database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout=10000; PRAGMA synchronous=FULL');
            $estimated = (int) $pdo->query('PRAGMA page_count')->fetchColumn() * (int) $pdo->query('PRAGMA page_size')->fetchColumn();
            if ($estimated > $this->maxBytes) { throw new RuntimeException('SQLite превышает бюджет резервных копий; увеличьте SQLITE_BACKUP_MAX_MIB или вынесите копии на другой диск.'); }
            $free = disk_free_space(dirname($this->database));
            if ($free !== false && $free < $estimated + 16 * 1024 * 1024) { throw new RuntimeException('Недостаточно места для нового снимка; существующие копии сохранены.'); }
            $destination = $this->database.'.backup-'.($kind === 'daily' ? 'daily-' : '').gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
            $partial = $destination.'.partial';
            try {
                $mask = umask(0077);
                try { $pdo->exec('VACUUM INTO '.$pdo->quote($partial)); } finally { umask($mask); }
                $this->verify($partial);
                if (filesize($partial) > $this->maxBytes) { throw new RuntimeException('Новая копия превышает бюджет; старые копии сохранены.'); }
                if (!chmod($partial, 0600) || !rename($partial, $destination)) { throw new RuntimeException('Не удалось опубликовать резервную копию.'); }
            } catch (Throwable $error) {
                if (is_file($partial) && !is_link($partial)) { unlink($partial); }
                throw $error;
            }
            return $this->prune($destination, true);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function verify(string $path): void
    {
        if (filesize($path) < 512 || file_get_contents($path, length: 16) !== "SQLite format 3\0") {
            throw new RuntimeException('Резервная копия не содержит файл SQLite.');
        }
        $copy = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $copy->exec('PRAGMA query_only=ON');
        if ($copy->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
            throw new RuntimeException('Резервная копия не прошла проверку SQLite.');
        }
    }

    /** @return list<array{path: string, kind: string, stamp: string, bytes: int}> */
    private function copies(): array
    {
        $files = [];
        $pattern = '/^'.preg_quote(basename($this->database), '/').'\.backup-(daily-)?([0-9]{8}-[0-9]{6})-[a-f0-9]{6}$/D';
        foreach (new \DirectoryIterator(dirname($this->database)) as $entry) {
            if ($entry->isLink() || !$entry->isFile() || !preg_match($pattern, $entry->getFilename(), $match)) { continue; }
            $date = DateTimeImmutable::createFromFormat('!Ymd-His', $match[2], new DateTimeZone('UTC'));
            if (!$date || $date->format('Ymd-His') !== $match[2]) { continue; }
            $files[] = ['path' => $entry->getPathname(), 'kind' => $match[1] === 'daily-' ? 'daily' : 'deploy', 'stamp' => $match[2], 'bytes' => $entry->getSize()];
        }
        usort($files, fn (array $a, array $b): int => strcmp($b['stamp'], $a['stamp']) ?: strcmp($b['path'], $a['path']));
        return $files;
    }

    /** @return array{path: string, created: bool, deleted: int, count: int, bytes: int} */
    private function prune(string $protected, bool $created): array
    {
        $files = $this->copies();
        // Только что проверенный снимок всегда первый, даже при смене часов сервера.
        usort($files, fn (array $a, array $b): int => ($b['path'] === $protected) <=> ($a['path'] === $protected));
        $counts = ['daily' => 0, 'deploy' => 0]; $days = []; $bytes = 0; $deleted = 0; $count = 0;
        foreach ($files as $file) {
            $kind = $file['kind']; $day = substr($file['stamp'], 0, 8);
            $limit = $kind === 'daily' ? $this->dailyKeep : $this->deployKeep;
            $keep = $counts[$kind] < $limit && $bytes + $file['bytes'] <= $this->maxBytes && ($kind !== 'daily' || !isset($days[$day]));
            if (!$keep) {
                // Не удаляем ссылки и не используем шаблон rm: каждый путь принадлежит этому DB.
                if (is_link($file['path']) || !is_file($file['path']) || !unlink($file['path'])) { throw new RuntimeException('Не удалось удалить старую резервную копию.'); }
                $deleted++; continue;
            }
            $counts[$kind]++; $bytes += $file['bytes']; $count++;
            if ($kind === 'daily') { $days[$day] = true; }
        }
        // Прерванный VACUUM мог оставить только наш временный файл; чистим после исправной копии.
        foreach (new \DirectoryIterator(dirname($this->database)) as $entry) {
            $pattern = '/^'.preg_quote(basename($this->database), '/').'\.backup-(daily-)?[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.partial$/D';
            if (!$entry->isLink() && $entry->isFile() && preg_match($pattern, $entry->getFilename()) && $entry->getMTime() < time() - 86400) {
                if (!unlink($entry->getPathname())) { throw new RuntimeException('Не удалось удалить прерванную копию.'); }
                $deleted++;
            }
        }
        return ['path' => $protected, 'created' => $created, 'deleted' => $deleted, 'count' => $count, 'bytes' => $bytes];
    }
}
