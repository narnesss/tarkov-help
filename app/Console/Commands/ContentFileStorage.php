<?php
declare(strict_types=1);
namespace App\Console\Commands;

use App\Models\{ContentDataset, Quest};
use Illuminate\Console\Command;
use App\Services\DatabaseAccess;
use Illuminate\Support\Facades\DB;

/** Переносит существующий контент в файлы без замены ID, текстов и дат правок. */
final class ContentFileStorage extends Command
{
    protected $signature = 'wiki:content-files {--move : Вынести прежние JSON из SQLite в файлы}';
    protected $description = 'Проверить файловый контент или перенести прежние записи без перезаписи правок';

    public function handle(): int
    {
        $database = DatabaseAccess::configuredPath(); $lock = null;
        if ($database !== ':memory:') {
            $gate = new DatabaseAccess($database);
            if (file_exists($gate->marker())) { throw new \RuntimeException('Сначала завершите восстановление базы.'); }
            $lock = $gate->acquire(exclusive: (bool) $this->option('move'), wait: 60);
            if ($lock === null) { throw new \RuntimeException('База занята другой операцией.'); }
        }
        try { return $this->transfer(); }
        finally { if ($lock !== null) { flock($lock, LOCK_UN);fclose($lock); } }
    }

    private function transfer(): int
    {
        $moved = 0; $checked = 0;
        foreach ([Quest::class, ContentDataset::class] as $class) {
            foreach ($class::orderBy('id')->cursor() as $record) {
                $original = $record->getRawOriginal('payload');
                if (!str_starts_with($original, '@file:')) {
                    if (!$this->option('move')) { $this->error('В SQLite остался прежний контент. Запустите --move после публикации совместимого релиза.'); return self::FAILURE; }
                    if ($record instanceof Quest) { $record->payload = $record->payload; }
                    else { $record->setContent($record->content()); }
                    $reference = $record->getAttributes()['payload'];
                    if ($class::whereKey($record->id)->where('payload', $original)->toBase()->update(['payload' => $reference]) !== 1) {
                        $this->error('Запись изменилась во время переноса. Повторите команду.'); return self::FAILURE;
                    }
                    $record->refresh(); $moved++;
                }
                $record->json(); $checked++;
            }
        }
        if ($moved > 0 && DB::getDriverName()==='sqlite'&&DB::connection()->getDatabaseName()!==':memory:') {
            // Убираем прежние JSON также из освобождённых страниц файла SQLite.
            DB::statement('VACUUM');
        }
        $this->info('Проверено файлов: '.$checked.'. Перенесено записей: '.$moved.'. JSON контента в SQL отсутствует.');
        return self::SUCCESS;
    }
}
