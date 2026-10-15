<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Contracts\Cache\Repository;

/** Публичные маршруты из JSON-каталога; готовые HTML не нужны PHP-серверу. */
final readonly class PageCatalog
{
    public function __construct(private Repository $cache) {}

    /** Возвращает содержимое JSON, инвалидируя кэш при обновлении файла. */
    public function routes(): array
    {
        $file = public_path('data/page-routes.json');
        $key = 'site:routes:'.hash_file('sha256', $file);
        return $this->cache->remember($key, config('site.cache_ttl'), function () use ($file): array {
            $index = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            $routes = [];
            $add = function (string $url, string $template, string $title, array $params) use (&$routes): void {
                if (!str_starts_with($url, '/') || str_contains($url, '..') || str_contains($url, '\\') || str_contains($url, "\0")) {
                    throw new \LogicException('Unsafe route: '.$url);
                }
                if (isset($routes[$url])) { throw new \LogicException('Duplicate route: '.$url); }
                $routes[$url] = compact('template', 'title', 'params');
            };
            foreach ($index['quests'] as $id => $item) {
                $add('/quests/'.$item['slug'], 'quest', $item['name'], ['quest' => $id]);
                if ($id !== $item['slug']) { $add('/quests/'.$id, 'quest', $item['name'], ['quest' => $id]); }
            }
            foreach ($index['traders'] as $id => $item) {
                $title = 'Квесты: '.$item['name'];
                $add('/quests/trader/'.$item['slug'], 'quests', $title, ['trader' => $id]);
                if ($id !== $item['slug']) { $add('/quests/trader/'.$id, 'quests', $title, ['trader' => $id]); }
            }
            foreach (['all' => 'Все квесты', 'story' => 'Основной сюжет', 'archive' => 'Архив квестов'] as $section => $title) {
                $add('/quests/'.$section, 'quests', $title, ['section' => $section]);
            }
            foreach ([['maps','raid','raid','map'], ['documents','documents','documents','map'], ['bosses','boss','boss','boss'], ['sourceMaps','quest-map','quest-map-source','map']] as [$group,$prefix,$template,$key]) {
                foreach ($index[$group] as $id => $item) { $add('/'.$prefix.'/'.$id, $template, $item['name'], [$key => $id]); }
            }
            foreach ($index['items'] as $id => $item) { $add('/reference/items/'.$id, 'reference', $item['name'], ['tab' => 'items', 'item' => $id]); }
            foreach (['items'=>'Предметы','ammo'=>'Патроны','needed'=>'Для квестов','barters'=>'Бартеры','crafts'=>'Крафты','hideout'=>'Убежище','bosses'=>'Боссы','traders'=>'Торговцы','achievements'=>'Достижения','btr'=>'Маршруты БТР'] as $tab => $title) {
                $add('/reference/'.$tab, 'reference', $title, ['tab' => $tab]);
            }
            return $routes;
        });
    }

    /** Разрешает только известные страницы и legacy-алиасы без обхода каталогов. */
    public function resolve(string $path): ?array
    {
        $path = rawurldecode($path);
        if (str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '\\')) { return null; }
        $plain = preg_replace('/\.(?:html|php)$/', '', rtrim($path, '/')) ?: '/';
        $aliases = ['/' => 'index', '/index' => 'index', '/documents' => 'documents', '/raid' => 'raid', '/quest' => 'quest', '/quests' => 'quests', '/reference' => 'reference', '/boss' => 'boss', '/quest-map-source' => 'quest-map-source'];
        $aliases['/account'] = 'account';
        $aliases['/privacy'] = 'privacy';
        if (isset($aliases[$plain])) {
            $template = $aliases[$plain];
            return ['template' => $template, 'title' => config('pages.'.$template.'.title'), 'params' => []];
        }
        $entry = $this->routes()[$plain] ?? null;
        if ($entry) { $entry['title'] .= ' — Escape from Tarkov Wiki'; }
        return $entry;
    }
}
