<?php
declare(strict_types=1);
namespace App\Console\Commands;

use App\Services\SqliteBackups;
use App\Services\MysqlBackups;
use App\Services\SqlDatabaseAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Ручной выбор и проверка снимка; запись разрешена только с явным подтверждением. */
final class RestoreSqlite extends Command
{
    protected $signature = 'wiki:restore {--list} {--check} {--backup=} {--sha256=} {--confirm=}';
    protected $description = 'Просмотр, проверка и ручное восстановление выбранного SQL-бэкапа';

    public function handle(): int
    {
        $service = config('database.default') !== 'sqlite' ? new MysqlBackups((string)config('database.default')) : new SqliteBackups((string) config('database.connections.sqlite.database'),
            (int) config('backup.daily_keep'), (int) config('backup.deploy_keep'), (int) config('backup.max_bytes'));
        if ($this->option('list')) {
            foreach ($service->catalog() as $copy) { $this->line(json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
            return self::SUCCESS;
        }
        $name = (string) $this->option('backup'); $sha = (string) $this->option('sha256');
        if ($this->option('check')) {
            $service->check($name, $sha);
            $this->info('Копия, SHA-256 и схема проверены; база не изменялась.');
            return self::SUCCESS;
        }
        if ($this->option('confirm') !== 'RESTORE') {
            $this->error('Нужно --confirm=RESTORE: данные после выбранного снимка будут заменены.');
            return self::FAILURE;
        }
        $result = $service->restore($name, $sha, function (): void {
            DB::purge();
            SqlDatabaseAudit::foreignKeys(DB::connection()->getPdo());
            // Старый снимок не должен вновь разрешить ранее отозванные cookie-сессии.
            DB::table('sessions')->delete();
            if (\Illuminate\Support\Facades\Schema::hasTable('admin_sessions')) {
                DB::table('admin_sessions')->delete();
                DB::table('admin_users')->update(['remember_token' => null]);
            }
            DB::disconnect();
        });
        $this->info('Восстановлена копия: '.$result['source']);
        $this->info('База перед восстановлением сохранена: '.$result['safety']);
        return self::SUCCESS;
    }
}
