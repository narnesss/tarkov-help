<?php
declare(strict_types=1);
namespace App\Console\Commands;

use App\Services\SqliteBackups;
use Illuminate\Console\Command;

/** Создаёт проверенный приватный бэкап и ограничивает хранение старых копий. */
final class BackupSqlite extends Command
{
    protected $signature = 'wiki:backup {--kind=daily : daily или deploy}';
    protected $description = 'Согласованная резервная копия SQLite и очистка старых снимков';

    public function handle(): int
    {
        if (config('database.default') !== 'sqlite') { $this->error('Нужна SQLite.'); return self::FAILURE; }
        $service = new SqliteBackups((string) config('database.connections.sqlite.database'),
            (int) config('backup.daily_keep'), (int) config('backup.deploy_keep'), (int) config('backup.max_bytes'));
        $result = $service->create((string) $this->option('kind'));
        $this->info(($result['created'] ? 'Создана копия: ' : 'Копия за сегодня уже есть: ').$result['path']);
        $this->info(sprintf('Хранится: %d копий, %d байт; удалено старых: %d.', $result['count'], $result['bytes'], $result['deleted']));
        return self::SUCCESS;
    }
}
