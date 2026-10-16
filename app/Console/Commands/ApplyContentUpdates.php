<?php

declare(strict_types=1);
namespace App\Console\Commands;

use App\Services\ContentUpdates;
use Illuminate\Console\Command;

/** Применяет проверенные пакеты один раз после первоначального импорта. */
final class ApplyContentUpdates extends Command
{
    protected $signature='wiki:content-updates {--dry-run : Проверить пакет без записи}';
    protected $description='Применить точечные обновления квестов и карт без изменения ID и прогресса';

    public function handle(ContentUpdates $updates): int
    {
        $files=glob(database_path('content-updates/*.json'));sort($files);
        foreach($files as $file){$result=$updates->apply(json_decode(file_get_contents($file),true,128,JSON_THROW_ON_ERROR),(bool)$this->option('dry-run'));
            $this->info(basename($file).': '.json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
        return self::SUCCESS;
    }
}
