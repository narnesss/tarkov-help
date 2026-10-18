<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{ContentDataset, Quest};
use Illuminate\Support\Facades\{Cache, Schema};

/** Публичные требования квестов и убежища; личный прогресс не попадает в общий кэш. */
final class ImportantItems
{
    public const PAGE_SIZE = 24;

    public function listing(array $params = [], bool $cms = false): array
    {
        $mode = $params['mode'] ?? 'pve';
        $query = $params['q'] ?? '';
        $kind = $params['kind'] ?? 'all';
        $fir = $params['fir'] ?? 'all';
        $rows = $this->catalog($mode, $cms);
        $needle = mb_strtolower($query, 'UTF-8');
        $rows = array_values(array_filter($rows, static fn ($row) => ($needle === '' || str_contains(mb_strtolower($row['name'].' '.$row['shortName'], 'UTF-8'), $needle))));
        foreach ($rows as &$row) {
            $row['uses'] = array_values(array_filter($row['uses'], static fn ($use) => ($kind === 'all' || $use['kind'] === $kind) && ($fir !== 'fir' || $use['foundInRaid'])));
        }
        unset($row);
        $rows = array_values(array_filter($rows, static fn ($row) => $row['uses'] !== []));
        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = (int) ($params['page'] ?? 1);
        abort_if($page < 1 || $page > $pages, 404);
        return compact('mode', 'query', 'kind', 'fir', 'total', 'pages', 'page') + ['rows' => array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE)];
    }

    public static function href(array $listing, int $page = 1): string
    {
        return '/important-items?'.http_build_query(['mode' => $listing['mode'], 'q' => $listing['query'], 'kind' => $listing['kind'], 'fir' => $listing['fir'], 'page' => $page], '', '&', PHP_QUERY_RFC3986);
    }

    private function catalog(string $mode, bool $cms): array
    {
        abort_unless($cms && Schema::hasTable('content_datasets'), 503, 'Справочник временно недоступен.');
        $paths = ["guide/quests-$mode.json", "guide/reference-$mode.json", "guide/items-$mode.json"];
        if ($cms && Schema::hasTable('content_datasets')) {
            $datasets = ContentDataset::whereIn('path', $paths)->get()->keyBy('path');
            abort_unless($datasets->count() === 3, 503, 'Справочник временно недоступен.');
            $quests = Quest::where('mode', $mode)->where('published', true)->orderBy('id')->get(['id', 'quest_id', 'name', 'payload']);
            $revision = hash('sha256', serialize([$datasets->map->getAttributes()->all(), $quests->map->getAttributes()->all(), config('content.directory')]));
            $key = 'important-items-v1-'.hash('sha256', serialize([config('database.default'), config('database.connections.'.config('database.default').'.database'), $mode, config('content.directory')]));
            $cached = Cache::get($key);
            if (is_array($cached) && ($cached['revision'] ?? null) === $revision) { return $cached['rows']; }
            $tasks = $quests->map(static function (Quest $quest): array {
                $data = json_decode($quest->json(), true, 128, JSON_THROW_ON_ERROR);
                $data = array_intersect_key($data, array_flip(['minPlayerLevel', 'cmsArchived', 'catalogSection', 'objectives']));
                $data['objectives'] = array_map(static fn (array $goal): array => array_intersect_key($goal, array_flip(['id', 'type', 'item', 'items', 'count', 'foundInRaid', 'optional', 'cmsArchived'])), $data['objectives'] ?? []);
                return [...$data, 'id' => $quest->quest_id, 'name' => $quest->name];
            })->all();
            $reference = PublicJson::select($datasets[$paths[1]]->json(), ['hideout', 'crafts']);
            $items = PublicJson::select($datasets[$paths[2]]->json(), [], ['items' => static fn (array $item): array => array_intersect_key($item, array_flip(['id', 'name', 'shortName', 'iconLink', 'cmsArchived']))]);
            $rows = self::aggregate($tasks, $reference, $items);
            Cache::put($key, ['revision' => $revision, 'rows' => $rows], config('content.public_cache_ttl', 86400));
            return $rows;
        }
        abort(503, 'Справочник временно недоступен.');
    }

    /** Одно требование поиска/передачи считается один раз; альтернативы явно обозначены. */
    public static function aggregate(array $tasks, array $reference, array $catalog): array
    {
        $items = [];
        foreach ($catalog['items'] ?? [] as $item) {
            if (!empty($item['cmsArchived'])) { continue; }
            $items[$item['id']] = $item;
        }
        $uses = [];
        $idOf = static fn ($value) => is_array($value) ? ($value['id'] ?? '') : (is_string($value) ? $value : '');
        foreach ($tasks as $task) {
            if (!empty($task['cmsArchived']) || ($task['catalogSection'] ?? '') === 'supplementary') { continue; }
            foreach ($task['objectives'] ?? [] as $goal) {
                if (!empty($goal['cmsArchived']) || !in_array($goal['type'] ?? '', ['findItem', 'giveItem'], true)) { continue; }
                $ids = array_values(array_unique(array_filter(array_map($idOf, $goal['items'] ?? [$goal['item'] ?? null]))));
                foreach ($ids as $id) {
                    if (!isset($items[$id])) { continue; }
                    $key = 'quest:'.$task['id'];
                    $old = $uses[$id][$key] ?? null;
                    $uses[$id][$key] = ['kind' => 'quest', 'id' => (string) $task['id'], 'name' => $task['name'], 'level' => (int) ($task['minPlayerLevel'] ?? 0),
                        'count' => max((float) ($goal['count'] ?? 1), $old['count'] ?? 0),
                        'foundInRaid' => !empty($goal['foundInRaid']) || ($old['foundInRaid'] ?? false),
                        'optional' => !empty($goal['optional']) && ($old['optional'] ?? true), 'alternative' => count($ids) > 1 && ($old['alternative'] ?? true)];
                }
            }
        }
        foreach ($reference['hideout'] ?? [] as $station) {
            if (!empty($station['cmsArchived'])) { continue; }
            foreach ($station['levels'] ?? [] as $level) {
                if (!empty($level['cmsArchived'])) { continue; }
                foreach ($level['itemRequirements'] ?? [] as $requirement) {
                    if (!empty($requirement['cmsArchived'])) { continue; }
                    $id = $idOf($requirement['item'] ?? null);
                    if (!isset($items[$id])) { continue; }
                    $key = 'hideout:'.$level['id'];
                    $uses[$id][$key] = ['kind' => 'hideout', 'id' => (string) $level['id'], 'name' => $station['name'], 'level' => $level['level'],
                        'count' => (float) ($requirement['count'] ?? 1) + ($uses[$id][$key]['count'] ?? 0),
                        'foundInRaid' => !empty($requirement['attributes']['foundInRaid']), 'optional' => false, 'alternative' => false];
                }
            }
        }
        $craftable = [];
        foreach ($reference['crafts'] ?? [] as $craft) {
            if (!empty($craft['cmsArchived'])) { continue; }
            $id = $idOf($craft['productItem']['item'] ?? null);
            if ($id !== '') { $craftable[$id] = true; }
        }
        $rows = [];
        foreach ($uses as $id => $requirements) {
            $item = $items[$id];
            $icon = $item['iconLink'] ?? '';
            $rows[] = ['id' => $id, 'name' => $item['name'], 'shortName' => $item['shortName'] ?? '',
                'icon' => $icon !== '' && AdminInput::safeUrl($icon) ? $icon : '', 'craftable' => isset($craftable[$id]), 'uses' => array_values($requirements)];
        }
        usort($rows, static fn ($a, $b) => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])) ?: strcmp($a['id'], $b['id']));
        return $rows;
    }
}
