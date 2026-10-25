<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{ContentDataset, Quest};
use Illuminate\Support\Facades\{Cache, Log};

/** Карта опубликованных URL; даты берутся из записей, а не из времени сборки. */
final class SiteMap
{
    private const MAX_SCANNED = 45000;
    private const MAX_URLS = 45000;
    private const MAX_BYTES = 1572864;

    public function __construct(private PageSeo $seo, private PageCatalog $catalog, private QuestSeo $quests) {}

    /** Статический экспорт не обращается к БД и нужен только для совместимости релизов. */
    public function xml(string $path, bool $static = false): string
    {
        abort_unless(in_array($path, ['/sitemap.xml', '/sitemaps/pages.xml', '/sitemaps/quests.xml', '/sitemaps/items-pve.xml', '/sitemaps/items-regular.xml'], true), 404);
        $origin = $this->seo->origin();
        $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $head = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        if ($path === '/sitemap.xml') {
            $paths = $static ? ['/sitemaps/pages.xml'] : ['/sitemaps/pages.xml', '/sitemaps/quests.xml', '/sitemaps/items-pve.xml', '/sitemaps/items-regular.xml'];
            $xml = $head.'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($paths as $child) { $xml .= '<sitemap><loc>'.$escape($origin.$child).'</loc></sitemap>'; }
            $xml .= '</sitemapindex>'; $this->within(strlen($xml), self::MAX_BYTES, 'bytes');
            return $xml;
        }
        $xml = $head.'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $seen = []; $count = 0; $scanned = 0;
        $scan = function (int $rows = 1) use (&$scanned): void {
            $scanned += $rows; $this->within($scanned, self::MAX_SCANNED, 'scanned');
        };
        $append = function (string $url, ?string $modified = null) use (&$xml, &$seen, &$count, $escape): void {
            if (isset($seen[$url])) { return; }
            $this->within(++$count, self::MAX_URLS, 'urls');
            $entry = '<url><loc>'.$escape($url).'</loc>'.($modified !== null ? '<lastmod>'.$escape($modified).'</lastmod>' : '').'</url>';
            $this->within(strlen($xml) + strlen($entry) + strlen('</urlset>'), self::MAX_BYTES, 'bytes');
            $seen[$url] = true; $xml .= $entry;
        };
        if ($path === '/sitemaps/quests.xml') {
            // Актуальные квесты нельзя выводить из устаревшего списка экспорта.
            abort_if($static, 503, 'Карта квестов доступна через сервер содержимого.');
            $index = json_decode(file_get_contents(public_path('data/page-routes.json')), true, 64, JSON_THROW_ON_ERROR);
            foreach (Quest::where('published', true)->whereIn('mode', ['pve', 'regular'])->select(['id', 'quest_id', 'mode', 'updated_at', 'payload'])->lazyById(200) as $quest) {
                $scan();
                if (!empty($this->quests->flags($quest)['cmsArchived'])) { continue; }
                $append($origin.$this->quests->questPath($quest->quest_id, $quest->mode, $index), $quest->updated_at?->utc()->toAtomString());
            }
        } elseif (in_array($path, ['/sitemaps/items-pve.xml', '/sitemaps/items-regular.xml'], true)) {
            abort_if($static, 503, 'Карта предметов доступна через сервер содержимого.');
            $mode = $path === '/sitemaps/items-regular.xml' ? 'regular' : 'pve';
            foreach ($this->publishedIdentifiers('guide/items-'.$mode.'.json', 'items', $scan) as $id) {
                $append($origin.'/reference/items/'.$id.($mode === 'regular' ? '?mode=regular' : ''));
            }
        } else {
            $seo = $this->seo->catalogSnapshot();
            $entries = $this->catalog->routes();
            foreach (config('pages') as $page => $meta) { $entries[$page === 'index' ? '/' : '/'.$page] = ['template'=>$page, 'params'=>[], 'title'=>$meta['title']]; }
            foreach ($entries as $entry) {
                $scan();
                if ($entry['template'] === 'quest' || $entry['template'] === 'quest-map-source') { continue; }
                if (!$static && $this->isEntityPage($entry)) { continue; }
                $meta = $seo->metadata($entry['template'], $entry['params'], $entry['title']);
                if (str_contains($meta['robots'], 'noindex') || $meta['canonical'] === null) { continue; }
                $append($meta['canonical']);
            }
            if (!$static) {
                foreach ([['guide/bosses.json', 'profiles', '/boss/', true], ['raid/maps.json', null, '/raid/', false], ['maps.json', null, '/documents/', false]] as [$source, $collection, $prefix, $slug]) {
                    foreach ($this->publishedIdentifiers($source, $collection, $scan, $slug) as $id) { $append($origin.$prefix.$id); }
                }
                foreach (Quest::where('published', true)->whereIn('mode', ['pve', 'regular'])->select(['id', 'trader_id', 'mode', 'payload'])->lazyById(200) as $quest) {
                    $scan();
                    if (!$quest->trader_id) { continue; }
                    $flags = $this->quests->flags($quest);
                    if (!empty($flags['cmsArchived']) || !empty($flags['story']) || ($flags['catalogSection'] ?? null) === 'supplementary') { continue; }
                    $meta = $seo->metadata('quests', ['trader'=>$quest->trader_id, 'mode'=>$quest->mode]);
                    $append($meta['canonical']);
                }
            }
        }
        return $xml.'</urlset>';
    }

    /** Сохраняет только канонические ID текущей публикации, без объединения игровых режимов. */
    private function publishedIdentifiers(string $path, ?string $collection, callable $scan, bool $preferSlug = false): array
    {
        $dataset = ContentDataset::where('path', $path)->first(['id', 'path', 'payload']);
        if (!$dataset) { return []; }
        $scope = serialize([$dataset->getConnection()->getDatabaseName(), config('content.directory'), $path, $dataset->revision(), $preferSlug]);
        $decoded = false;
        $selected = Cache::remember('seo:sitemap-canonical-ids:'.hash('sha256', $scope), config('content.public_cache_ttl', 86400), function () use ($dataset, $collection, $scan, $preferSlug, &$decoded): array {
            $decoded = true; $rows = 0;
            $select = function ($row) use (&$rows, $scan, $preferSlug): ?string {
                $scan(); $rows++;
                if (!is_array($row) || !empty($row['cmsArchived'])) { return null; }
                $id = $preferSlug ? ($row['slug'] ?? $row['id'] ?? null) : ($row['id'] ?? null);
                return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id) ? $id : null;
            };
            $raw = $dataset->json(); $field = $collection ?? 'rows';
            $data = PublicJson::select($collection === null ? '{"rows":'.$raw.'}' : $raw, [], [$field=>$select]);
            return ['ids'=>array_values(array_unique($data[$field] ?? [])), 'rows'=>$rows];
        });
        if (!$decoded) { $scan($selected['rows']); }
        return $selected['ids'];
    }

    private function isEntityPage(array $entry): bool
    {
        $params = $entry['params'];
        foreach (['quests'=>'trader', 'reference'=>'item', 'boss'=>'boss', 'raid'=>'map', 'documents'=>'map'] as $template=>$parameter) {
            if ($entry['template'] === $template && array_key_exists($parameter, $params)) { return true; }
        }
        return false;
    }

    private function within(int $count, int $maximum, string $kind): void
    {
        if ($count <= $maximum) { return; }
        Log::warning('Превышен лимит генерации sitemap.', ['kind'=>$kind, 'count'=>$count, 'limit'=>$maximum]);
        abort(503, 'Карта сайта временно недоступна. Попробуйте позже.');
    }
}
