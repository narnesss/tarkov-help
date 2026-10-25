<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{ContentDataset, Quest};
use Illuminate\Support\Facades\Cache;

/** Публичный текст карточек и каталогов из текущих данных, без личного состояния. */
final class EntitySeo
{
    private const SIZE = 100;
    private const TABS = ['items'=>'Предметы', 'ammo'=>'Патроны', 'needed'=>'Предметы для квестов', 'barters'=>'Бартеры', 'crafts'=>'Крафты', 'hideout'=>'Убежище', 'bosses'=>'Боссы', 'traders'=>'Торговцы', 'achievements'=>'Достижения', 'btr'=>'Маршруты БТР'];
    private int $scanned = 0;

    public function content(string $page, array $params = []): array
    {
        $this->scanned = 0;
        $mode = $params['mode'] ?? 'pve'; $number = $params['page'] ?? 1;
        abort_unless(in_array($mode, ['pve', 'regular'], true) && (is_int($number) || is_string($number)) && preg_match('/^[1-9][0-9]{0,3}$/D', (string) $number), 404);
        $number = (int) $number;
        $key = match ($page) {'reference'=>'item', 'boss'=>'boss', 'raid', 'documents'=>'map', default=>null};
        abort_unless($key, 404);
        if (array_key_exists($key, $params)) {
            $id = $this->id($params[$key]); abort_unless($id !== '' && $number === 1, 404);
            return match ($page) {
                'reference'=>$this->item($id, $mode), 'boss'=>$this->boss($id, $mode),
                'raid'=>$this->raid($id, $mode), 'documents'=>$this->documents($id, $mode),
            };
        }
        return $this->catalog($page, $params, $mode, $number);
    }

    private function item(string $id, string $mode): array
    {
        $row = $this->one('guide/items-'.$mode.'.json', 'items', $id);
        $facts = [];
        foreach (['weight'=>['Масса', ' кг'], 'width'=>['Ширина', ' яч.'], 'height'=>['Высота', ' яч.']] as $key=>[$label, $unit]) { $this->numberFact($facts, $row, $key, $label, $unit); }
        $properties = $row['properties'] ?? [];
        if ($caliber = $this->text($properties['caliber'] ?? null)) { $facts[] = 'Калибр: '.preg_replace('/^Caliber/', '', $caliber); }
        foreach (['damage'=>'Урон', 'penetrationPower'=>'Пробитие', 'armorDamage'=>'Урон броне', 'ergonomics'=>'Эргономика', 'recoilVertical'=>'Вертикальная отдача', 'recoilHorizontal'=>'Горизонтальная отдача', 'armorClass'=>'Класс брони', 'maxDurability'=>'Максимальная прочность'] as $key=>$label) { $this->numberFact($facts, $properties, $key, $label); }
        $id = $this->id($row['id'] ?? $id);
        return $this->result($this->name($row), $this->text($row['description'] ?? null), $facts, [['name'=>'Все предметы', 'url'=>$this->url('/reference/items', $mode)]], '/reference/items/'.$id) + ['canonicalParams'=>['item'=>$id, 'tab'=>'items']];
    }

    private function boss(string $id, string $mode): array
    {
        $row = $this->one('guide/bosses.json', 'profiles', $id); $facts = []; $links = [];
        if ($faction = $this->text($row['faction'] ?? null)) { $facts[] = 'Фракция: '.$faction; }
        $this->numberFact($facts, $row, 'healthTotal', 'Общее здоровье');
        foreach ($row['health'] ?? [] as $part) { if (is_array($part)) { $this->numberFact($facts, $part, 'max', $this->text($part['bodyPart'] ?? $part['id'] ?? '') ?: 'Часть тела'); } }
        foreach ($row['notes'] ?? [] as $note) { if ($text = $this->text($note)) { $facts[] = $text; } }
        $mob = $row['spawnParentMobId'] ?? $row['id'];
        $activeMaps = [];
        $this->select('raid/maps.json', null, function (array $map) use (&$activeMaps): void { if ($id = $this->id($map['id'] ?? null)) { $activeMaps[$id] = true; } });
        $this->select('guide/reference-'.$mode.'.json', 'maps', function (array $map) use (&$facts, &$links, $row, $mob, $mode, $activeMaps): void {
            $found = false;
            foreach ($map['bosses'] ?? [] as $spawn) {
                if (!empty($spawn['cmsArchived']) || ($spawn['mob'] ?? '') !== $mob) { continue; }
                $found = true; $line = $this->name($map);
                if (is_numeric($spawn['spawnChance'] ?? null) && is_finite((float) $spawn['spawnChance'])) { $line .= ': шанс появления в снимке '.round((float) $spawn['spawnChance'] * 100, 2).'%'; }
                $facts[] = $line;
            }
            $slug = $this->id($map['normalizedName'] ?? null);
            if (isset($activeMaps[$slug]) && ($found || in_array($slug, $row['canonicalMapSlugs'] ?? [], true))) { $links[] = ['name'=>$this->name($map), 'url'=>$this->url('/raid/'.$slug, $mode)]; }
        });
        $links[] = ['name'=>'Все боссы', 'url'=>$this->url('/boss', $mode)];
        $id = $this->id($row['slug'] ?? $row['id']);
        return $this->result($this->name($row), $this->text($row['description'] ?? null), $facts, $links, '/boss/'.$id) + ['canonicalParams'=>['boss'=>$id]];
    }

    private function raid(string $id, string $mode): array
    {
        $id = DocumentMapOverlay::ALIASES[$id] ?? $id;
        $map = $this->one('raid/maps.json', null, $id); $facts = []; $exits = [];
        $data = $this->select('raid/'.($mode === 'pve' ? 'pve/' : '').$id.'.json', 'pois', function (array $point) use (&$exits): void {
            if (($point['kind'] ?? null) !== 'extract' || !empty($point['hiddenByDefault']) || ($point['sourceStatus'] ?? null) === 'unverified-coordinates') { return; }
            if (count($exits) < 100 && ($name = $this->text($point['name'] ?? null))) {
                $conditions = array_filter(array_map($this->text(...), $point['conditions'] ?? []));
                $exits[] = 'Выход: '.$name.($conditions ? ' — '.implode('; ', $conditions) : '');
            }
        }, ['mapId', 'mapInfo']);
        if (($data['mapId'] ?? null) !== $id) { $data = []; $exits = []; }
        $info = $data['mapInfo'] ?? [];
        $this->numberFact($facts, $info, 'raidDuration', 'Продолжительность рейда', ' мин.');
        if ($players = $this->text($info['players'] ?? null)) { $facts[] = 'Игроков: '.$players; }
        $this->numberFact($facts, $info, 'minPlayerLevel', 'Минимальный уровень');
        foreach ([$map['baseFloor'] ?? [], ...($map['floors'] ?? [])] as $floor) { if (empty($floor['cmsArchived']) && ($name = $this->text($floor['name'] ?? null))) { $facts[] = 'Уровень карты: '.$name; } }
        return $this->result($this->name($map), $this->text($info['description'] ?? $map['description'] ?? null), [...$facts, ...$exits], [['name'=>'Все карты', 'url'=>$this->url('/raid', $mode)]], '/raid/'.$id) + ['canonicalParams'=>['map'=>$id]];
    }

    private function documents(string $id, string $mode): array
    {
        $id = array_search($id, DocumentMapOverlay::ALIASES, true) ?: $id;
        $map = $this->one('maps.json', null, $id); $facts = []; $count = 0;
        $this->select('spawns.json', null, function (array $spawn) use ($id, &$facts, &$count): void {
            if (($spawn['map'] ?? null) !== $id) { return; } $count++;
            if (count($facts) >= 100) { return; }
            $name = $this->text($spawn['docName'] ?? null); $caption = $this->text($spawn['caption'] ?? null);
            if ($name || $caption) { $facts[] = ($name ?: 'Документ').($caption ? ': '.$caption : ''); }
        });
        array_unshift($facts, 'Опубликованных мест документов: '.$count);
        return $this->result($this->name($map), 'Документы Kord Breach: '.$this->name($map).'. Места и ориентиры из текущего каталога.', $facts, [['name'=>'Все карты документов', 'url'=>$this->url('/documents', $mode)]], '/documents/'.$id) + ['canonicalParams'=>['map'=>$id]];
    }

    private function catalog(string $page, array $params, string $mode, int $number): array
    {
        $tab = $params['tab'] ?? null;
        if ($page === 'reference' && $tab === null) {
            abort_if($number > 1, 404); $links = [];
            foreach (self::TABS as $key=>$name) { $links[] = ['name'=>$name, 'url'=>$this->url('/reference/'.$key, $mode)]; }
            return $this->result('Справочник Escape from Tarkov', 'Предметы, патроны, рецепты, торговцы и другие разделы справочника.', [], $links, '/reference');
        }
        abort_if($page === 'reference' && (!is_string($tab) || !isset(self::TABS[$tab])), 404);
        $name = $page === 'reference' ? self::TABS[$tab] : ['boss'=>'Боссы', 'raid'=>'Карты', 'documents'=>'Документы Kord Breach'][$page];
        $path = '/'.$page.($tab ? '/'.$tab : ''); $total = 0; $rows = []; $facts = []; $links = [];
        $accept = function (array $row) use (&$total, &$rows, $number): void { if ($total >= ($number - 1) * self::SIZE && count($rows) < self::SIZE) { $rows[] = $row; } $total++; };
        if ($page === 'reference' && in_array($tab, ['items', 'ammo', 'needed'], true)) {
            $needed = $tab === 'needed' ? $this->needed($mode) : null;
            $this->select('guide/items-'.$mode.'.json', 'items', function (array $row) use ($accept, $tab, $needed): void {
                if ($tab === 'ammo' && ($row['properties']['propertiesType'] ?? '') !== 'ItemPropertiesAmmo') { return; }
                if ($needed !== null && !isset($needed[$row['id'] ?? ''])) { return; }
                if ($this->id($row['id'] ?? null)) { $accept(array_intersect_key($row, array_flip(['id', 'name', 'shortName', 'description']))); }
            });
            foreach ($rows as $row) { $links[] = ['name'=>$this->name($row), 'url'=>$this->url('/reference/items/'.$row['id'], $mode)]; }
        } elseif ($page === 'boss' || $tab === 'bosses') {
            $this->select('guide/bosses.json', 'profiles', $accept);
            foreach ($rows as $row) { if ($id = $this->id($row['slug'] ?? $row['id'] ?? null)) { $links[] = ['name'=>$this->name($row), 'url'=>$this->url('/boss/'.$id, $mode)]; } }
        } elseif ($tab === 'btr') {
            $names = [];
            $this->select('raid/maps.json', null, function (array $map) use (&$names): void { $names[$map['id'] ?? ''] = $this->name($map); });
            $data = $this->select('guide/btr.json', 'unused', static function (array $row): void {}, ['maps', 'meta']);
            if ($note = $this->text($data['meta']['note'] ?? null)) { $facts[] = $note; }
            if (in_array($data['meta']['sourceGameMode'] ?? null, ['pve', 'regular'], true)) { $facts[] = 'Режим исходного снимка: '.($data['meta']['sourceGameMode'] === 'pve' ? 'PVE' : 'PVP'); }
            foreach ($data['maps'] ?? [] as $map) {
                $this->scan(); if (!is_array($map) || !empty($map['cmsArchived']) || !($id = $this->id($map['mapId'] ?? null)) || !isset($names[$id])) { continue; }
                foreach ($map['routes'] ?? [] as $route) { $this->scan(); if (empty($route['cmsArchived'])) { $accept(['map'=>$id, 'name'=>$this->text($route['label'] ?? $route['id'] ?? null)]); } }
            }
            foreach ($rows as $row) { if (isset($names[$row['map']])) { $links[] = ['name'=>$names[$row['map']].' · '.$row['name'], 'url'=>$this->url('/raid/'.$row['map'], $mode, ['category'=>'btr'])]; } }
        } elseif (in_array($page, ['raid', 'documents'], true)) {
            $this->select($page === 'documents' ? 'maps.json' : 'raid/maps.json', null, $accept);
            foreach ($rows as $row) { if ($id = $this->id($row['id'] ?? null)) { $links[] = ['name'=>$this->name($row), 'url'=>$this->url(($page === 'documents' ? '/documents/' : '/raid/').$id, $mode, $tab === 'btr' ? ['category'=>'btr'] : [])]; } }
        } else {
            $this->select('guide/reference-'.$mode.'.json', $tab, $accept);
            $ids = [];
            foreach ($rows as $row) { foreach (['offeredItem', 'productItem'] as $key) { if ($id = $this->id($row[$key]['item'] ?? null)) { $ids[$id] = true; } } }
            $names = [];
            if ($ids) { $this->select('guide/items-'.$mode.'.json', 'items', function (array $item) use ($ids, &$names): void { if (isset($ids[$item['id'] ?? ''])) { $names[$item['id']] = $this->name($item); } }); }
            foreach ($rows as $row) {
                $id = $this->id($row['offeredItem']['item'] ?? $row['productItem']['item'] ?? null);
                if ($id && isset($names[$id])) {
                    $line = $names[$id]; $count = $row['offeredItem']['count'] ?? $row['productItem']['count'] ?? null;
                    if (is_numeric($count)) { $line .= ' × '.$count; }
                    if (isset($row['duration']) && is_numeric($row['duration'])) { $line .= ' · производство: '.$row['duration'].' с'; }
                    if (is_numeric($row['minTraderLevel'] ?? null)) { $line .= ' · уровень торговца: '.$row['minTraderLevel']; }
                    if (is_numeric($row['level'] ?? null)) { $line .= ' · уровень модуля: '.$row['level']; }
                    $facts[] = $line; $links[$id] = ['name'=>$names[$id], 'url'=>$this->url('/reference/items/'.$id, $mode)];
                } else {
                    $line = $this->name($row); $description = $this->text($row['description'] ?? null);
                    if ($description) { $line .= ': '.$description; }
                    if (isset($row['levels']) && is_array($row['levels'])) { $line .= ' · уровней: '.count(array_filter($row['levels'], static fn ($level) => empty($level['cmsArchived']))); }
                    $facts[] = $line;
                    if ($tab === 'traders' && ($id = $this->id($row['id'] ?? null))) { $links[] = ['name'=>'Квесты: '.$this->name($row), 'url'=>$this->url('/quests/trader/'.$id, $mode)]; }
                }
            }
        }
        $pages = max(1, (int) ceil($total / self::SIZE)); abort_if($number > $pages, 404);
        array_unshift($facts, 'Опубликованных записей: '.$total);
        $result = $this->result($name, $name.' в Escape from Tarkov. Актуальные записи справочника для '.($mode === 'regular' ? 'PVP' : 'PVE').'.', $facts, array_values($links), $path);
        return $result + compact('number', 'pages', 'total') + ['previousUrl'=>$number > 1 ? $this->url($path, $mode, $number > 2 ? ['page'=>$number - 1] : []) : null, 'nextUrl'=>$number < $pages ? $this->url($path, $mode, ['page'=>$number + 1]) : null];
    }

    private function needed(string $mode): array
    {
        $ids = [];
        foreach (Quest::where('mode', $mode)->where('published', true)->select(['id', 'payload'])->lazyById(100) as $quest) {
            $this->scan(); $decoded = false;
            $scope = serialize([$quest->getConnection()->getDatabaseName(), config('content.directory'), $quest->revision(), $mode]);
            $selected = Cache::remember('seo:needed-items:'.hash('sha256', $scope), config('content.public_cache_ttl', 86400), function () use ($quest, $mode, &$decoded): array {
                $decoded = true; $ids = []; $rows = 0;
                $task = PublicJson::select($quest->json(), ['cmsArchived', 'catalogSection'], ['objectives'=>function (array $goal) use ($mode, &$ids, &$rows) {
                    $this->scan(); $rows++;
                    if (!empty($goal['cmsArchived']) || !in_array($goal['type'] ?? '', ['giveItem', 'findItem'], true) || isset($goal['modes']) && !in_array($mode, $goal['modes'], true)) { return null; }
                    $items = $goal['items'] ?? [$goal['item'] ?? null];
                    if (count($items) === 1 && ($id = $this->id(is_array($items[0]) ? ($items[0]['id'] ?? null) : $items[0]))) { $ids[$id] = true; }
                    return null;
                }]);
                if (!empty($task['cmsArchived']) || ($task['catalogSection'] ?? '') === 'supplementary') { $ids = []; }
                return compact('ids', 'rows');
            });
            if (!$decoded) { $this->scan($selected['rows']); }
            $ids += $selected['ids'];
        }
        return $ids;
    }

    private function one(string $path, ?string $collection, string $id): array
    {
        $selected = null;
        $this->select($path, $collection, function (array $row) use ($id, &$selected): void { if ($selected === null && in_array($id, [$row['id'] ?? null, $row['slug'] ?? null, $row['normalizedName'] ?? null], true)) { $selected = $row; } });
        abort_unless($selected, 404); return $selected;
    }

    private function select(string $path, ?string $collection, callable $visit, array $fields = []): array
    {
        $dataset = ContentDataset::where('path', $path)->first(); if (!$dataset) { return []; }
        $raw = $dataset->json(); $key = $collection ?? 'rows';
        return PublicJson::select($collection === null ? '{"rows":'.$raw.'}' : $raw, $fields, [$key=>function ($row) use ($visit) {
            $this->scan(); if (is_array($row) && empty($row['cmsArchived'])) { $visit($row); } return null;
        }]);
    }

    private function scan(int $rows = 1): void
    { $this->scanned += $rows; abort_if($this->scanned > 45000, 503, 'Страница временно недоступна. Попробуйте позже.'); }

    private function result(string $name, string $description, array $facts, array $links, string $url): array
    {
        $description = $description ?: $name.' в Escape from Tarkov.';
        return ['entity'=>['name'=>$name, 'description'=>$description, 'facts'=>array_slice(array_values(array_unique(array_map($this->text(...), $facts))), 0, 300), 'links'=>array_slice($links, 0, 150), 'url'=>$url], 'title'=>$name, 'description'=>mb_strimwidth(preg_replace('/\s+/u', ' ', $description), 0, 230, '…')];
    }

    private function name(array $row): string
    { return $this->text($row['name'] ?? $row['displayName'] ?? $row['shortName'] ?? $row['id'] ?? ''); }

    private function text(mixed $value): string
    { return is_string($value) ? mb_substr($value, 0, 4000) : ''; }

    private function id(mixed $value): string
    { return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $value) ? $value : ''; }

    private function numberFact(array &$facts, array $row, string $key, string $label, string $unit = ''): void
    { if (isset($row[$key]) && is_numeric($row[$key]) && is_finite((float) $row[$key])) { $facts[] = $label.': '.$row[$key].$unit; } }

    private function url(string $path, string $mode, array $query = []): string
    { $query = [...($mode === 'regular' ? ['mode'=>'regular'] : []), ...$query]; return $path.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''); }
}
