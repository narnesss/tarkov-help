<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;

/** Общая блокировка API/бэкапов и исключительная блокировка восстановления. */
final class DatabaseAccess
{
    public function __construct(private readonly string $database) {}

    public function marker(): string { return $this->database.'.restoring'; }

    /** @return resource|null */
    public function acquire(bool $exclusive = false, int $wait = 0)
    {
        if (!$exclusive && file_exists($this->marker())) { return null; }
        $path = $this->database.'.access.lock';
        if (is_link($path)) { throw new RuntimeException('Блокировка БД не должна быть ссылкой.'); }
        $mask = umask(0077);
        try { $lock = fopen($path, 'c'); } finally { umask($mask); }
        if ($lock === false) { throw new RuntimeException('Нет доступа к блокировке БД.'); }
        $deadline = microtime(true) + $wait;
        do {
            if (flock($lock, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
                if (!$exclusive && file_exists($this->marker())) { fclose($lock); return null; }
                return $lock;
            }
            if (microtime(true) >= $deadline) { break; }
            usleep(100000);
        } while (true);
        fclose($lock);
        return null;
    }
}
