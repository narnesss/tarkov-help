<?php
declare(strict_types=1);
namespace App\Console\Commands;

use App\Models\{ContentDataset, Quest};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Первоначальный импорт публичных снимков без перезаписи работы редакторов. */
final class ImportContent extends Command
{
    protected $signature = 'wiki:import-content {--source= : Каталог, подготовленный npm run content:seed}';
    protected $description = 'Добавить исходные данные сайта и квесты в SQLite без перезаписи существующих записей';

    public function handle(): int
    {
        $directory = $this->option('source') ?: (is_dir(database_path('content-seed')) ? database_path('content-seed') : base_path('build/content-seed'));
        if (!is_file($directory.'/datasets.json') || !is_file($directory.'/quests.jsonl.gz')) {
            $this->error('Нет исходного импорта. Выполните npm run content:seed.'); return self::FAILURE;
        }
        $paths = json_decode(file_get_contents($directory.'/datasets.json'), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($directory, $paths): void {
            foreach ($paths as $path) {
                if (!preg_match('~^[a-z0-9][a-z0-9/._-]*\.json$~', $path) || str_contains($path, '..')) {
                    throw new \RuntimeException('Некорректное имя набора данных.');
                }
                $dataset = ContentDataset::firstOrNew(['path' => $path]);
                if ($dataset->exists) { continue; }
                $dataset->payload = base64_encode(file_get_contents($directory.'/datasets/'.$path.'.gz'));
                $dataset->save();
            }
            $stream = gzopen($directory.'/quests.jsonl.gz', 'rb');
            try {
                while (($line = gzgets($stream)) !== false) {
                    ['mode' => $mode, 'task' => $task] = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
                    Quest::firstOrCreate(['quest_id' => $task['id'], 'mode' => $mode], [
                        'name' => $task['name'], 'trader_id' => $task['trader']['id'] ?? null,
                        'published' => true, 'payload' => $task,
                    ]);
                }
            } finally { gzclose($stream); }
        });
        $this->info('Данные импортированы. Существующие квесты, черновики и редакторские правки сохранены.');
        return self::SUCCESS;
    }
}
