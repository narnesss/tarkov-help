<?php
declare(strict_types=1);
namespace App\Console\Commands;
use App\Services\MysqlMigration;
use Illuminate\Console\Command;
use Throwable;

/** Не выводит SQL-значения и реквизиты подключения даже при ошибках переноса. */
final class MigrateMysql extends Command
{
    protected $signature='wiki:migrate-mysql {access : Приватный файл подключения} {--activate : Переключить после успешного пробного переноса}';
    protected $description='Перенос SQLite в MariaDB с проверкой всех строк, ID и файлов';
    public function handle(): int
    {
        try{$result=(new MysqlMigration((string)$this->argument('access')))->run((bool)$this->option('activate'));$this->line(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return self::SUCCESS;}
        catch(Throwable $error){$this->error('Перенос остановлен: '.get_class($error).'. Реквизиты и содержимое строк не выводятся.');
            if($error instanceof \RuntimeException&&!$error instanceof \PDOException&&!$error instanceof \Illuminate\Database\QueryException){$this->error($error->getMessage());}return self::FAILURE;}
    }
}
