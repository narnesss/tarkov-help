<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\View\Factory;

/** Кэш общих Blade-страниц; пользовательские данные сюда не передаются. */
final readonly class PageViews
{
    public function __construct(private Repository $cache, private Factory $views, private ImportantItems $importantItems) {}

    /** Отпечаток исходников совпадает с проверкой Node-сборки. */
    public function fingerprint(): string
    {
        $names = ['public/data/page-routes.json', 'config/pages.php', 'config/legal.php', 'app/Services/PageCatalog.php', 'app/Services/PageViews.php', 'app/Services/PageModules.php', 'app/Console/Commands/ExportSite.php'];
        array_push($names, 'config/site.php', 'app/Services/PageSeo.php', 'app/Services/SiteMap.php');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) { $names[] = 'resources/views/'.substr($file->getPathname(), strlen(resource_path('views')) + 1); }
        }
        foreach (['*.mjs', 'vendor/*.mjs'] as $pattern) {
            foreach (glob(public_path($pattern)) as $file) { $names[] = 'public/'.str_replace('\\', '/', substr($file, strlen(public_path()) + 1)); }
        }
        sort($names, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($names as $name) {
            $name = str_replace('\\', '/', $name);
            hash_update($hash, $name."\0".str_replace("\r\n", "\n", file_get_contents(base_path($name)))."\0");
        }
        return hash_final($hash);
    }

    /** Рендерит публичную оболочку; runtime, маршрут и исходники разделяют cache keys. */
    public function render(string $page, string $runtime = 'php', ?string $title = null, array $params = [], array $serverContent = []): string
    {
        $meta = config('pages.'.$page);
        if (!$meta || !in_array($runtime, ['php', 'static'], true)) { throw new \InvalidArgumentException('Unknown page'); }
        $title ??= $meta['title'];
        $seoService = app(PageSeo::class);
        $seo = $seoService->metadata($page, $params, $title);
        if (isset($serverContent['description'])) { $seo = $seoService->withDescription($seo, $serverContent['description']); }
        $title = $seo['title'];
        if ($serverContent !== []) {
            $view = (int) ($serverContent['number'] ?? $params['page'] ?? 1) > 1 && isset($serverContent['pages'])
                ? (isset($serverContent['entity']) ? 'entity-directory' : 'quest-directory') : $page;
            return $this->views->make('pages.'.$view, compact('page', 'meta', 'runtime', 'title', 'params', 'seo', 'serverContent'))->render();
        }
        if ($page === 'important-items') {
            $listing = $runtime === 'php' ? $this->importantItems->listing($params, true) : null;
            return $this->views->make('pages.'.$page, compact('page', 'meta', 'runtime', 'title', 'params', 'listing', 'seo', 'serverContent'))->render();
        }
        $key = 'site:view:'.$this->fingerprint().':'.hash('sha256', json_encode([$page, $runtime, $title, $params], JSON_THROW_ON_ERROR));
        $data = compact('page', 'meta', 'runtime', 'title', 'params', 'seo', 'serverContent');
        return $this->cache->remember($key, config('site.cache_ttl'), fn () => $this->views->make('pages.'.$page, $data)->render());
    }
}
