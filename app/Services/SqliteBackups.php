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
        $access = (new DatabaseAccess($this->database))->acquire(wait: 60);
        if ($access === null) { throw new RuntimeException('База закрыта для восстановления.'); }
        try { return $this->locked(fn () => $this->createSnapshot($kind)); }
        finally { flock($access, LOCK_UN); fclose($access); }
    }

    private function locked(callable $operation): mixed
    {
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
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function createSnapshot(string $kind): array
    {
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
    }

    /** Список содержит только имена и контрольные суммы, без содержимого БД. */
    public function catalog(): array
    {
        return $this->locked(function (): array {
            return array_map(function (array $file): array {
                try { $this->verify($file['path']); $valid = true; } catch (Throwable) { $valid = false; }
                return ['name' => basename($file['path']), 'bytes' => $file['bytes'], 'sha256' => hash_file('sha256', $file['path']), 'valid' => $valid];
            }, $this->copies());
        });
    }

    public function check(string $name, string $sha256): void
    {
        $this->locked(fn () => $this->selected($name, $sha256));
    }

    private function selected(string $name, string $sha256): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $sha256)) { throw new RuntimeException('Нужен SHA-256 из списка копий.'); }
        foreach ($this->copies() as $file) {
            if (basename($file['path']) !== $name) { continue; }
            if (!hash_equals(hash_file('sha256', $file['path']), $sha256)) { throw new RuntimeException('Контрольная сумма копии изменилась.'); }
            $this->verify($file['path']);
            if ($this->schema($file['path']) !== $this->schema($this->database)) { throw new RuntimeException('Схема или журнал миграций копии несовместимы с текущим релизом.'); }
            return $file['path'];
        }
        throw new RuntimeException('Копия не найдена: допускается точное имя обычного файла из списка, без путей и ссылок.');
    }

    private function schema(string $path): array
    {
        $pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = $pdo->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE substr(name,1,7) <> 'sqlite_' ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC);
        $migrations = $pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='migrations'")->fetchColumn();
        return [$schema, $migrations ? $pdo->query('SELECT migration,batch FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_ASSOC) : []];
    }

    /** Сохраняем текущую БД и восстанавливаем через SQLite API без смены inode/WAL. */
    public function restore(string $name, string $sha256, callable $validate): array
    {
        if (!class_exists(\SQLite3::class)) { throw new RuntimeException('Для восстановления нужно расширение sqlite3.'); }
        $gate = new DatabaseAccess($this->database);
        $access = $gate->acquire(exclusive: true, wait: 60);
        if ($access === null) { throw new RuntimeException('Не удалось дождаться завершения запросов API.'); }
        try {
            return $this->locked(function () use ($name, $sha256, $validate, $gate): array {
                $source = $this->selected($name, $sha256);
                $originalSchema = $this->schema($this->database);
                $stage = $this->database.'.restore-'.bin2hex(random_bytes(8)).'.partial';
                $safety = null; $attempted = false; $resume = false;
                try {
                    // Сохраняем выбранный снимок до ротации: retention может удалить его имя.
                    $mask = umask(0077);
                    try {
                        $selected = new PDO('sqlite:'.$source);
                        $selected->exec('PRAGMA synchronous=FULL');
                        $selected->exec('VACUUM INTO '.$selected->quote($stage));
                        $selected = null;
                    } finally { umask($mask); }
                    $this->verify($stage);
                    $safety = $this->createSnapshot('deploy')['path'];
                    $marker = json_encode(['source' => $name, 'safety' => basename($safety), 'started_utc' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR);
                    if (is_link($gate->marker()) || file_put_contents($gate->marker(), $marker, LOCK_EX) === false || !chmod($gate->marker(), 0600)) {
                        throw new RuntimeException('Не удалось включить защиту API перед восстановлением.');
                    }
                    $attempted = true;
                    $this->replaceFrom($stage);
                    $this->verify($this->database);
                    if ($this->schema($this->database) !== $originalSchema) { throw new RuntimeException('После восстановления изменилась схема.'); }
                    $validate();
                    $resume = true;
                    return ['source' => $name, 'safety' => basename($safety)];
                } catch (Throwable $error) {
                    if ($attempted && $safety !== null) {
                        try {
                            $this->replaceFrom($safety);
                            $this->verify($this->database);
                            if ($this->schema($this->database) !== $originalSchema) { throw new RuntimeException('Схема аварийной копии не совпала.'); }
                            $resume = true;
                        } catch (Throwable $rollback) {
                            throw new RuntimeException('Не удалось вернуть сохранённую БД; API оставлен закрытым. Копия: '.basename($safety), previous: $rollback);
                        }
                    }
                    throw new RuntimeException('Восстановление не завершено; текущая БД сохранена'.($safety ? ': '.basename($safety) : '').'. '.$error->getMessage(), previous: $error);
                } finally {
                    if ($resume && file_exists($gate->marker()) && !unlink($gate->marker())) { throw new RuntimeException('БД проверена, но не удалось снять маркер восстановления.'); }
                    if (is_file($stage) && !is_link($stage)) { unlink($stage); }
                }
            });
        } finally { flock($access, LOCK_UN); fclose($access); }
    }

    private function replaceFrom(string $path): void
    {
        $source = new \SQLite3($path, SQLITE3_OPEN_READONLY);
        $target = new \SQLite3($this->database, SQLITE3_OPEN_READWRITE);
        $source->enableExceptions(true); $target->enableExceptions(true);
        try {
            $source->busyTimeout(10000); $target->busyTimeout(10000);
            $target->exec('PRAGMA synchronous=FULL');
            if (!$source->backup($target)) { throw new RuntimeException('SQLite Backup API не завершился.'); }
        } finally { $target->close(); $source->close(); }
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
        if ($copy->query('PRAGMA foreign_key_check')->fetchAll() !== []) { throw new RuntimeException('Копия содержит нарушенные связи SQLite.'); }
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
