<?php
declare(strict_types=1);
namespace App\Console\Commands;

use App\Services\WikiImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Идемпотентный перенос актуальных файлов в предметную схему. */
final class ImportWikiDomain extends Command
{
    protected $signature='wiki:import-domain';
    protected $description='Перенести предметные сущности и связи в SQLite без перезаписи файлов';
    public function handle(WikiImporter $importer): int
    {
        $importer->rebuild(fn(string $message)=>$this->line($message));
        $this->info('Предметная схема: '.DB::table('wiki_items')->count().' предметов, '.DB::table('wiki_quests')->count().' квестов, '.DB::table('wiki_map_points')->count().' точек.');
        $this->info('Неизвестных исходных ссылок: '.DB::table('wiki_import_issues')->count().'. Исходные значения сохранены в файлах.');
        return self::SUCCESS;
    }
}
