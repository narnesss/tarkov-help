<?php
declare(strict_types=1);
namespace App\Console\Commands;

use App\Services\{PageCatalog, PageViews};
use Illuminate\Console\Command;

/** Экспортирует общие Blade-оболочки и каталог для статической сборки Cloudflare. */
final class ExportSite extends Command
{
    protected $signature = 'site:export';
    protected $description = 'Экспорт публичных страниц Laravel в статическую сборку';

    public function handle(PageCatalog $catalog, PageViews $views): int
    {
        foreach (array_keys(config('pages')) as $page) {
            $filename = $page === 'not-found' ? '404' : $page;
            file_put_contents(public_path($filename.'.html'), $views->render($page, 'static'));
        }
        file_put_contents(public_path('data/site-render-routes.json'), json_encode($catalog->routes(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        file_put_contents(public_path('data/view-export.json'), json_encode(['source_hash' => $views->fingerprint()], JSON_THROW_ON_ERROR)."\n");
        $this->info('Blade-страницы и публичные маршруты экспортированы.');
        return self::SUCCESS;
    }
}
