<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{ContentDataset, ContentReference, Quest};
use Illuminate\Support\Facades\DB;

/** Собирает поисковые названия из текущих файлов, не меняя игровой контент. */
final class ReferenceIndexer
{
    public function rebuild(): void
    {
        DB::transaction(function (): void {
            ContentReference::query()->update(['active' => false]);
            // Подробные игровые карточки перекрывают краткие подписи из наборов карты.
            foreach (ContentDataset::orderByRaw("CASE WHEN path LIKE 'guide/%' THEN 1 ELSE 0 END")->orderBy('path')->cursor() as $dataset) { $this->dataset($dataset); }
            foreach (Quest::orderBy('id')->cursor() as $quest) { $this->quest($quest); }
        });
    }

    public function quest(Quest $quest): void
    {
        ContentReference::upsert([['kind' => 'quest', 'variant' => $quest->mode, 'value' => $quest->quest_id,
            'label' => $quest->name.($quest->published ? '' : ' · черновик'), 'search_text' => mb_strtolower($quest->name),
            'source_dataset_id' => null, 'source_pointer' => '', 'quest_record_id' => $quest->id, 'active' => true]],
            ['kind', 'variant', 'value'], ['label', 'search_text', 'quest_record_id', 'active']);
    }

    public function dataset(ContentDataset $dataset): void
    {
        $path = $dataset->path;
        if (!preg_match('~^(maps|docs|spawns|raid/(maps|loose-items|item-names|(?:pve/)?[a-z-]+)|guide/(items-(?:pve|regular)|quests-(?:pve|regular)|reference-(?:pve|regular)))\.json$~', $path)) { return; }
        $data = $dataset->content();$rows = [];
        $put = function (string $kind, string $variant, mixed $id, mixed $name, string $pointer, array $aliases = []) use (&$rows, $dataset): void {
            if (!is_string($id) || $id === '' || !is_string($name) || $name === '') { return; }
            $label = mb_substr($name, 0, 500);
            $search = implode(' ', [$name, ...array_filter($aliases, 'is_string')]);
            $rows[$kind.'|'.$variant.'|'.$id] = ['kind' => $kind, 'variant' => $variant, 'value' => $id, 'label' => $label,
                'search_text' => mb_strtolower($search.' '.preg_replace('/[\s_-]+/u', ' ', $search)),
                'source_dataset_id' => $dataset->id, 'source_pointer' => $pointer, 'quest_record_id' => null, 'active' => true];
        };
        $group = function (array $records, string $kind, string $variant, string $prefix) use ($put): void {
            foreach ($records as $key => $record) {
                if (!is_array($record)) { continue; }
                $id = $record['id'] ?? (is_string($key) ? $key : null);
                $put($kind, $variant, $id, $record['name'] ?? $record['displayName'] ?? null,
                    $prefix.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key),
                    [$record['shortName'] ?? '', $record['normalizedName'] ?? '', $record['en'] ?? '', $record['nameEn'] ?? '']);
            }
        };
        if ($path === 'maps.json') { $group($data, 'location', 'slug', ''); }
        elseif ($path === 'docs.json') { $group($data, 'document', 'default', ''); }
        elseif ($path === 'spawns.json') {
            foreach ($data as $i => $point) {
                $put('point', 'document', $point['id'] ?? null, ($point['docName'] ?? 'Документ').' — '.($point['caption'] ?? '').' · '.($point['mapName'] ?? ''), '/'.$i);
            }
        } elseif ($path === 'raid/maps.json') {
            $names = ContentDataset::where('path', 'maps.json')->first()?->content() ?? [];
            $names = array_column($names, 'name', 'id');
            foreach ($data as $i => $map) {
                $put('location', 'slug', $map['id'], $names[$map['id']] ?? $map['displayName'], '/'.$i);
                foreach (['baseFloor' => [$map['baseFloor'] ?? []], 'floors' => $map['floors'] ?? []] as $field => $floors) {
                    foreach ($floors as $j => $floor) { $put('floor', $map['id'], $floor['id'] ?? null, $floor['name'] ?? null, '/'.$i.'/'.$field.($field === 'floors' ? '/'.$j : '')); }
                }
            }
        } elseif ($path === 'raid/item-names.json') {
            foreach ($data as $id => $name) { $put('item', 'default', $id, $name, '/'.$id); }
        } elseif (str_starts_with($path, 'raid/')) {
            $group($data['items'] ?? [], 'item', 'default', '/items');
            foreach ($data['pois'] ?? [] as $i => $point) {
                $put('point', str_contains($path, '/pve/') ? 'pve' : 'regular', $point['id'] ?? null,
                    ($point['name'] ?? $point['category'] ?? 'Точка').' · '.($data['mapInfo']['name'] ?? $data['mapId'] ?? ''), '/pois/'.$i, $point['aliases'] ?? []);
            }
        } else {
            foreach (['items' => 'item', 'questItems' => 'item', 'maps' => 'location', 'traders' => 'trader',
                'skills' => 'skill', 'hideout' => 'station', 'achievements' => 'achievement'] as $field => $kind) {
                $group($data[$field] ?? [], $kind, $kind === 'location' ? 'game' : 'default', '/'.$field);
            }
            $names = ContentReference::where('kind', 'item')->pluck('label', 'value')->all();
            foreach ($data['crafts'] ?? [] as $i => $craft) {
                $item = $craft['productItem']['item'] ?? null;
                $put('craft', str_contains($path, 'pve') ? 'pve' : 'regular', $craft['id'] ?? null,
                    'Крафт: '.($names[is_array($item) ? ($item['id'] ?? '') : ($item ?? '')] ?? 'Рецепт'), '/crafts/'.$i);
            }
        }
        ContentReference::where('source_dataset_id', $dataset->id)->update(['active' => false]);
        foreach (array_chunk(array_values($rows), 100) as $chunk) {
            ContentReference::upsert($chunk, ['kind', 'variant', 'value'], ['label', 'search_text', 'source_dataset_id', 'source_pointer', 'active']);
        }
    }
}
