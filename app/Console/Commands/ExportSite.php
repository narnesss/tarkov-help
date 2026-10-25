<?php
declare(strict_types=1);
namespace App\Console\Commands;

use App\Services\{PageCatalog, PageViews, PageSeo, SiteMap};
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
        foreach(['boss-card'=>['boss',['boss'=>'']], 'item-card'=>['reference',['tab'=>'items','item'=>'']]] as $name=>[$page,$params]){
            file_put_contents(public_path($name.'.html'),$views->render($page,'static',null,$params));
        }
        $seoService = app(PageSeo::class)->catalogSnapshot(); $seoPages = [];
        foreach ($catalog->routes() as $path => $entry) {
            $seo = $seoService->metadata($entry['template'], $entry['params'], $entry['title']);
            $head = str_replace("\r\n", "\n", view('components.seo-head', compact('seo'))->render());
            $seoPages[$path] = ['title' => $seo['title'], 'head' => $head];
        }
        file_put_contents(public_path('data/seo-export.json'), json_encode($seoPages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        if (!is_dir(public_path('sitemaps'))) { mkdir(public_path('sitemaps'), 0755, true); }
        file_put_contents(public_path('sitemap.xml'), app(SiteMap::class)->xml('/sitemap.xml', true));
        file_put_contents(public_path('sitemaps/pages.xml'), app(SiteMap::class)->xml('/sitemaps/pages.xml', true));
        file_put_contents(public_path('robots.txt'), "User-agent: *\nAllow: /\nAllow: /api/content/\nDisallow: /admin\nDisallow: /api/\nDisallow: /quest-map/\nDisallow: /quest-map-source\n\nSitemap: ".$seoService->origin()."/sitemap.xml\n");
        file_put_contents(public_path('data/site-render-routes.json'), json_encode($catalog->routes(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        file_put_contents(public_path('data/view-export.json'), json_encode(['source_hash' => $views->fingerprint()], JSON_THROW_ON_ERROR)."\n");
        $this->info('Blade-страницы и публичные маршруты экспортированы.');
        return self::SUCCESS;
    }
}
