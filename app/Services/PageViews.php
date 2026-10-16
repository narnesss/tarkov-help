<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\View\Factory;

/** Кэш общих Blade-страниц; пользовательские данные сюда не передаются. */
final readonly class PageViews
{
    public function __construct(private Repository $cache, private Factory $views) {}

    /** Отпечаток исходников совпадает с проверкой Node-сборки. */
    public function fingerprint(): string
    {
        $names = ['public/data/page-routes.json', 'config/pages.php', 'config/legal.php', 'app/Services/PageCatalog.php', 'app/Services/PageViews.php'];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) { $names[] = 'resources/views/'.substr($file->getPathname(), strlen(resource_path('views')) + 1); }
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
    public function render(string $page, string $runtime = 'php', ?string $title = null, array $params = []): string
    {
        $meta = config('pages.'.$page);
        if (!$meta || !in_array($runtime, ['php', 'static'], true)) { throw new \InvalidArgumentException('Unknown page'); }
        $title ??= $meta['title'];
        $key = 'site:view:'.$this->fingerprint().':'.hash('sha256', json_encode([$page, $runtime, $title, $params], JSON_THROW_ON_ERROR));
        return $this->cache->remember($key, config('site.cache_ttl'), fn () => $this->views->make('pages.'.$page, ['page' => $page, 'meta' => $meta, 'runtime' => $runtime, 'title' => $title, 'params' => $params])->render());
    }
}
