<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{ContentDataset, ContentReference, ReferenceOptions};
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Fields\Relation;

/** Переводит выбор названия в прежнюю игровую ссылку, сохраняя форму условий. */
final class ContentLinks
{
    public function specification(array $document, string $path, mixed $value, array $context = [], bool $paginate = true): ?array
    {
        $parts = ContentFields::parts($path); $key = (string) end($parts);
        $mode = $context['mode'] ?? 'pve'; $source = $context['source'] ?? '';
        // Корневые справочники содержат определения, а не ссылки на них.
        if ($source !== '' && count($parts) <= (array_is_list($document) ? 1 : 2)) { return null; }
        $ancestor = $key;
        foreach (array_reverse($parts) as $part) { if (!ctype_digit((string) $part)) { $ancestor = $part; break; } }
        $kind = match ($ancestor) {
            'item', 'itemId', 'item_id', 'questItem', 'items', 'keys', 'key', 'keyId', 'requiredKeys',
            'usingWeapon', 'usingWeaponMods', 'wearing', 'notWearing', 'contains', 'excludedItems', 'allowedItems' => 'item',
            'map', 'mapId', 'map_id', 'maps', 'location', 'locationId', 'location_id', 'locations', 'locationUnlock' => 'location',
            'trader', 'traderId', 'trader_id', 'traderUnlock' => 'trader',
            'task', 'quest', 'quests', 'taskId', 'questId', 'quest_id', 'taskUnlock', 'questUnlock' => 'quest',
            'doc', 'document', 'documents', 'documentId' => 'document',
            'point', 'pointId', 'points', 'mapPoint', 'mapPoints', 'spawnId' => 'point',
            'skill' => 'skill', 'station' => 'station', 'achievement' => 'achievement',
            'craft', 'craftUnlock' => 'craft', 'floor' => 'floor', default => null,
        };
        if ($kind === null) {
            if ($key !== 'id' && is_string($value) && preg_match('/^[a-f0-9]{24,64}$/', $value)) {
                $known = ContentReference::where('value', $value)->orderByRaw('CASE WHEN variant = ? THEN 0 ELSE 1 END', [$mode])->first();
                if ($known !== null) { return ['kind' => $known->kind, 'variant' => $known->variant, 'multiple' => false]; }
            }
            return null;
        }
        $multiple = is_array($value) && !isset($value['id']);
        if ($multiple) {
            if (!array_is_list($value)) { return null; }
            if ($paginate && count($value) > ContentFields::PAGE_SIZE) { return null; }
            foreach ($value as $record) {
                if (is_array($record) && (!isset($record['id']) || isset($record['count'], $record['item']))) { return null; }
                if (!is_string($record) && !is_array($record)) { return null; }
            }
            // Награда — запись с количеством, её предмет выбирается внутри записи.
            if ($ancestor === 'items' && preg_match('~/(?:finishRewards|startRewards)(?:/items)?$~', $path)) { return null; }
        }
        if (!is_array($value) && !is_string($value) && $value !== null) { return null; }
        $first = $multiple ? ($value[0] ?? '') : $value;
        $id = is_array($first) ? ($first['id'] ?? '') : ($first ?? '');
        $variant = match ($kind) {
            'quest', 'craft' => $mode,
            'point' => $source === 'spawns.json' || str_contains($path, 'document') ? 'document' : $mode,
            'location' => $source === 'spawns.json' || str_starts_with($source, 'raid/') ? 'slug' : 'game',
            'floor' => (string) ($document['mapId'] ?? $document['map'] ?? ''), default => 'default',
        };
        if ($kind === 'location' && $id !== '') {
            $known = ContentReference::where('kind', 'location')->where('value', $id)->where('active', true)
                ->orderByRaw('CASE WHEN variant = ? THEN 0 ELSE 1 END', [$variant])->first();
            if ($known !== null) { $variant = $known->variant; }
        }
        if ($kind === 'floor') {
            $parent = $document;
            foreach (array_slice($parts, 0, -1) as $part) { $parent = $parent[$part]; }
            $variant = is_string($parent['map'] ?? null) ? $parent['map'] : $variant;
        }
        return ['kind' => $kind, 'variant' => $variant, 'multiple' => $multiple];
    }

    public function field(string $name, array $specification): Relation
    {
        $field = Relation::make($name)->fromClass(ReferenceOptions::class, 'label', 'id')
            ->applyScope('picker', $specification['kind'], $specification['variant'])->displayAppend('label')->searchColumns('label')->chunk(20)->allowEmpty()
            ->help('Начните вводить название. Можно искать по русскому или английскому названию.');
        return $specification['multiple'] ? $field->multiple() : $field;
    }

    public function selected(mixed $value, array $specification): mixed
    {
        $values = $specification['multiple'] ? $value : [$value]; $ids = [];
        $wanted = array_values(array_filter(array_map(
            static fn ($original) => is_array($original) ? ($original['id'] ?? null) : $original, $values
        ), static fn ($id) => is_string($id) && $id !== ''));
        if ($wanted === []) { return $specification['multiple'] ? [] : null; }
        $references = ContentReference::where('kind', $specification['kind'])->where('variant', $specification['variant'])
            ->whereIn('value', $wanted)->get()->keyBy('value');
        foreach ($values as $original) {
            $id = is_array($original) ? ($original['id'] ?? null) : $original;
            if (!is_string($id) || $id === '') { continue; }
            $reference = $references->get($id);
            // Старая связь остаётся доступной даже при удалении записи из источника.
            if ($reference === null) {
                $label = is_array($original) ? ($original['name'] ?? $original['shortName'] ?? null) : null;
                $reference = ContentReference::firstOrCreate(['kind' => $specification['kind'], 'variant' => $specification['variant'], 'value' => $id],
                    ['label' => $label ?: 'Сохранённая связь · название отсутствует в справочнике', 'search_text' => mb_strtolower($label ?? ''), 'active' => false]);
            }
            $ids[] = $reference->id;
        }
        return $specification['multiple'] ? $ids : ($ids[0] ?? null);
    }

    public function resolve(mixed $input, mixed $original, array $specification, string $field): mixed
    {
        $values = $specification['multiple'] ? (($input === null || $input === '') ? [] : $input) : (($input === null || $input === '') ? [] : [$input]);
        if (!is_array($values) || count($values) > 200 || count(array_unique($values, SORT_REGULAR)) !== count($values)) { $this->invalid($field); }
        $old = $specification['multiple'] ? $original : [$original]; $oldById = [];
        foreach ($old as $value) { $id = is_array($value) ? ($value['id'] ?? '') : $value; if (is_string($id)) { $oldById[$id] = $value; } }
        // Проверяем весь ввод до SQL и читаем выбранные связи одним запросом.
        if ($values === []) { return $specification['multiple'] ? [] : null; }
        $values = array_map(fn ($id) => $this->referenceId($id, $field), $values);
        $references = ContentReference::where('kind', $specification['kind'])->where('variant', $specification['variant'])
            ->whereIn('id', $values)->get()->keyBy('id');
        $results = [];
        foreach ($values as $id) {
            $reference = $references->get((int) $id);
            if ($reference === null || (!$reference->active && !array_key_exists($reference->value, $oldById))) { $this->invalid($field); }
            if (array_key_exists($reference->value, $oldById)) { $results[] = $oldById[$reference->value]; continue; }
            $template = $old[0] ?? null;
            $results[] = is_array($template) ? $this->entity($reference, $template) : $reference->value;
        }
        return $specification['multiple'] ? $results : ($results[0] ?? null);
    }

    private function entity(ContentReference $reference, array $template): array
    {
        $entity = null;
        if ($reference->source_dataset_id !== null) {
            $entity = ContentDataset::find($reference->source_dataset_id)?->content();
            foreach (ContentFields::parts($reference->source_pointer) as $part) { $entity = is_array($entity) ? ($entity[$part] ?? null) : null; }
        }
        if (is_array($entity) && ($entity['id'] ?? null) === $reference->value) {
            return array_intersect_key($entity, $template + ['id' => null, 'name' => null]);
        }
        // При смене сущности старые имя/иконка не должны переехать к новому ID.
        return ['id' => $reference->value, 'name' => $reference->label];
    }

    private function referenceId(mixed $id, string $field): int
    {
        if ((!is_int($id) && !is_string($id)) || !ctype_digit((string) $id)) { $this->invalid($field); }
        $digits = ltrim((string) $id, '0');
        $number = (int) $digits;
        if ((string) $number !== ($digits === '' ? '0' : $digits)) { $this->invalid($field); }
        return $number;
    }

    private function invalid(string $field): never
    { throw ValidationException::withMessages([$field => 'Выберите запись из списка по названию.']); }
}
