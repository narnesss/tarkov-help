<?php
declare(strict_types=1);
namespace App\Console\Commands;

use App\Models\ContentReference;
use App\Services\ReferenceIndexer;
use Illuminate\Console\Command;

/** Обновляет словари выбора Orchid после импорта или восстановления контента. */
final class IndexContentReferences extends Command
{
    protected $signature = 'wiki:reference-index';
    protected $description = 'Обновить поисковые названия предметов, локаций и остальных связей';
    public function handle(ReferenceIndexer $index): int
    {
        $index->rebuild();$this->info('Индекс выбора обновлён: '.ContentReference::where('active', true)->count().' названий.');
        return self::SUCCESS;
    }
}
