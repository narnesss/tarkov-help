<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Разбивает вложенные данные на разделы и типизированные поля без ввода JSON. */
final class ContentFields
{
    public const PAGE_SIZE = 60;

    public function section(array $document, string $path): array
    {
        $value = $document;
        foreach ($this->parts($path) as $part) {
            abort_unless(is_array($value) && array_key_exists($part, $value), 404);
            $value = $value[$part];
        }
        abort_unless(is_array($value), 404);
        return $value;
    }

    public function entries(array $document, string $path, int $page, array $context = []): array
    {
        $entries = [];
        $section = $this->section($document, $path);
        foreach ($section as $key => $value) {
            if(is_string($key)&&str_starts_with($key,'cms')){continue;}
            $child = $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            $label = is_int($key) ? 'Запись '.($key + 1) : $this->label((string) $key);
            if (preg_match('/^[a-f0-9]{24,64}$/', (string) $key)) { $label = 'Запись'; }
            if (is_array($value)) {
                $title = $value['name'] ?? $value['title'] ?? $value['description'] ?? null;
                if (is_string($title) && $title !== '') { $label .= ' — '.mb_substr($title, 0, 100); }
            }
            $reference = app(ContentLinks::class)->specification($document, $child, $value, $context);
            $entries[] = ['path' => $child, 'key' => hash('sha256', $child), 'label' => $label, 'reference' => $reference,
                'value' => $value, 'readonly' => $reference === null && ($key === 'id' || $key === 'quest_id' || preg_match('/(?:Id|_id)$/', (string) $key)
                    || ($key === 'mapName' && array_key_exists('map', $section)) || ($key === 'docName' && array_key_exists('doc', $section))
                    || (is_string($value) && preg_match('/^[a-f0-9]{24,64}$/', $value)))];
        }
        return array_slice($entries, (max(1, $page) - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
    }

    public function apply(array $document, string $path, int $page, array $fields, array $context = []): array
    {
        $known = [];
        foreach ($this->entries($document, $path, $page, $context) as $entry) {
            if ((!is_array($entry['value']) || $entry['reference'] !== null) && !$entry['readonly']) { $known[$entry['key']] = $entry; }
        }
        if (array_diff_key($fields, $known)) { throw ValidationException::withMessages(['fields' => 'Недопустимые поля формы. Обновите страницу.']); }
        $clearedLists = [];
        foreach ($fields as $key => $input) {
            $entry = $known[$key]; $original = $entry['value'];
            if ($entry['reference'] !== null) {
                $value = app(ContentLinks::class)->resolve($input, $original, $entry['reference'], 'fields.'.$key);
                $document = $this->replace($document, $entry['path'], $value);
                if (!$entry['reference']['multiple'] && $value === null && ctype_digit(basename($entry['path']))) {
                    $clearedLists[substr($entry['path'], 0, (int) strrpos($entry['path'], '/'))] = true;
                }
                $leaf = basename($entry['path']);
                if ($value !== $original && is_string($value) && in_array($leaf, ['map', 'doc'], true)) {
                    $parent = substr($entry['path'], 0, (int) strrpos($entry['path'], '/'));
                    $nameKey = $leaf === 'map' ? 'mapName' : 'docName';
                    if (array_key_exists($nameKey, $this->section($document, $parent))) {
                        $label = \App\Models\ContentReference::where('kind', $entry['reference']['kind'])->where('variant', $entry['reference']['variant'])->where('value', $value)->value('label');
                        $document = $this->replace($document, $parent.'/'.$nameKey, $label ?? '');
                    }
                }
                continue;
            }
            $rules = is_bool($original) ? ['required', 'boolean'] : (is_int($original)
                ? ['required', 'integer'] : (is_float($original) ? ['required', 'numeric'] : ['nullable', 'string', 'max:1000000']));
            $coordinate = ($context['source'] ?? '') === 'spawns.json' && preg_match('~^/\d+/(x|y|x2|y2)$~', $entry['path']);
            if ($coordinate) { $rules = [$original === null ? 'nullable' : 'required', 'numeric', 'between:0,100']; }
            Validator::make(['fields' => [$key => $input]], ['fields.'.$key => $rules])->validate();
            $value = match (true) { $coordinate => $input === null || $input === '' ? null : (float) $input,
                is_bool($original) => (bool) $input, is_int($original) => (int) $input,
                is_float($original) => (float) $input, $original === null && ($input === '' || $input === null) => null,
                default => (string) ($input ?? '') };
            $document = $this->replace($document, $entry['path'], $value);
        }
        foreach (array_keys($clearedLists) as $listPath) {
            $document = $this->replace($document, $listPath, array_values(array_filter($this->section($document, $listPath), fn ($value) => $value !== null)));
        }
        return $document;
    }

    public function append(array $document, string $path, array $input, array $context = []): array
    {
        $section = $this->section($document, $path);
        $link = app(ContentLinks::class)->specification($document, $path, $section, $context, false);
        if ($link !== null && $link['multiple']) {
            $value = app(ContentLinks::class)->resolve($input['reference'] ?? null, null, [...$link, 'multiple' => false], 'addition.reference');
            if ($value === null) { throw ValidationException::withMessages(['addition.reference' => 'Выберите запись по названию.']); }
            $ids = array_map(fn ($entry) => is_array($entry) ? ($entry['id'] ?? null) : $entry, $section);
            if (in_array($value, $ids, true)) { throw ValidationException::withMessages(['addition.reference' => 'Эта запись уже есть в списке.']); }
            if (is_array($section[0] ?? null)) {
                $value = app(ContentLinks::class)->resolve([$input['reference']], [$section[0]], [...$link, 'multiple' => true], 'addition.reference')[0];
            }
            $section[] = $value;
            return $this->replace($document, $path, $section);
        }
        $options = $this->additions($document, $path, $context);
        $data = Validator::make($input, ['preset' => ['required', \Illuminate\Validation\Rule::in(array_keys($options))],
            'value' => ['nullable', 'string', 'max:100000']])->validate();
        $preset = $options[$data['preset']]; $value = $preset['value'];
        if ($data['preset'] === 'record') { $value = $this->blank($value); $section[] = is_string($value) ? ($data['value'] ?? '') : $value; }
        elseif ($data['preset'] === 'dictionary') {
            $id = 'custom-'.bin2hex(random_bytes(12));
            $section[$id] = is_array($value) ? ['id' => $id, 'name' => $data['value'] ?? 'Новая запись'] : ($data['value'] ?? 'Новая запись');
        } else { $section[$data['preset']] = $value; }
        return $path === '' ? $section : $this->replace($document, $path, $section);
    }

    /** Готовые структуры избавляют редактора от технических имён полей. */
    public function additions(array $document, string $path, array $context = []): array
    {
        $section = $this->section($document, $path); $parts = self::parts($path); $key = (string) end($parts);
        $templates = [
            'objectives' => ['id' => '', 'description' => '', 'type' => 'findItem', 'count' => 1, 'items' => [], 'maps' => []],
            'items' => ['item' => null, 'count' => 1, 'attributes' => []],
            'requiredItems' => ['item' => null, 'count' => 1, 'attributes' => []],
            'traderStanding' => ['trader' => null, 'standing' => 0.01],
            'traderRequirements' => ['trader' => null, 'level' => 1],
            'taskRequirements' => ['task' => null, 'status' => ['complete']],
            'neededKeys' => ['map' => null, 'keys' => []],
            'skillLevelReward' => ['skill' => null, 'level' => 1],
            'offerUnlock' => ['item' => null, 'trader' => null, 'level' => 1],
            'zones' => ['id' => '', 'map' => null, 'position' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0]],
        ];
        $objectDefaults = [
            'finishRewards' => ['items' => [], 'traderStanding' => [], 'offerUnlock' => [], 'skillLevelReward' => []],
            'startRewards' => ['items' => []],
            'questContent' => ['walkthrough' => '', 'steps' => [], 'neededKeys' => [], 'gallery' => []],
        ];
        $isList = $section !== [] ? array_is_list($section) : isset($templates[$key]) || in_array($key, ['maps', 'keys', 'steps', 'gallery', 'paragraphs', 'images', 'traderUnlock', 'craftUnlock', 'taskRequirements', 'media'], true);
        if ($isList) {
            $sample = $section !== [] ? reset($section) : ($templates[$key] ?? '');
            return ['record' => ['label' => 'Новая запись', 'value' => $sample]];
        }
        if ($section !== [] && preg_match('/^[a-f0-9]{24,64}$/', (string) array_key_first($section))) {
            return ['dictionary' => ['label' => 'Новая запись справочника', 'value' => reset($section)]];
        }
        $defaults = $objectDefaults[$key] ?? (preg_match('~/objectives/\d+$~', $path) ? [
            'items' => [], 'maps' => [], 'requiredKeys' => [], 'zones' => [], 'foundInRaid' => false, 'optional' => false, 'count' => 1,
        ] : ($path === '' && empty($context['source']) ? ['taskRequirements' => [], 'neededKeys' => [], 'startRewards' => [], 'finishRewards' => [], 'questContent' => [], 'kappaRequired' => false, 'story' => false] : []));
        if (($context['source'] ?? '') === 'spawns.json' && preg_match('~^/\d+$~', $path)) {
            $defaults = ['items' => [], 'keys' => [], 'quests' => [], 'floor' => null, 'x2' => 0.0, 'y2' => 0.0, 'images' => []];
        }
        $result = [];
        foreach (array_diff_key($defaults, $section) as $field => $value) { $result[$field] = ['label' => $this->label($field), 'value' => $value]; }
        return $result;
    }

    private function blank(mixed $value, string|int $key = ''): mixed
    {
        if ($key === 'id') { return 'custom-'.bin2hex(random_bytes(12)); }
        if (in_array($key, ['item', 'map', 'trader', 'task', 'questItem', 'skill', 'station'], true)) { return null; }
        if (in_array($key, ['items', 'maps', 'keys', 'requiredKeys', 'usingWeapon', 'usingWeaponMods', 'wearing', 'notWearing'], true)) { return []; }
        if ($key === 'count') { return 1; }
        if (is_array($value)) { foreach ($value as $k => $v) { $value[$k] = $this->blank($v, $k); } return $value; }
        return match (true) { is_bool($value) => false, is_int($value), is_float($value) => 0,
            $key === 'type' => $value, $value === null => null, default => '' };
    }

    private function replace(array $document, string $path, mixed $value): array
    {
        $target = &$document;
        foreach ($this->parts($path) as $part) { $target = &$target[$part]; }
        $target = $value;
        return $document;
    }

    public static function parts(string $path): array
    {
        abort_unless(strlen($path) <= 2000 && ($path === '' || str_starts_with($path, '/')), 404);
        return $path === '' ? [] : array_map(fn ($part) => str_replace(['~1', '~0'], ['/', '~'], $part), explode('/', substr($path, 1)));
    }

    public function label(string $key): string
    {
        return ['objectives' => 'Цели', 'finishRewards' => 'Награды', 'startRewards' => 'Начальные награды',
            'questContent' => 'Прохождение и тексты', 'description' => 'Описание', 'name' => 'Название', 'title' => 'Заголовок',
            'id' => 'ID', 'type' => 'Действие', 'count' => 'Количество', 'items' => 'Предметы', 'item' => 'Предмет',
            'traderStanding' => 'Репутация торговцев', 'trader' => 'Торговец', 'standing' => 'Репутация',
            'offerUnlock' => 'Разблокировка предложений', 'skillLevelReward' => 'Развитие навыков', 'traderUnlock' => 'Открытие торговцев',
            'craftUnlock' => 'Разблокировка крафтов', 'achievement' => 'Достижения', 'customization' => 'Внешний вид',
            'traderDialogueUnlock' => 'Открытие диалогов', 'locationUnlock' => 'Открытие локаций',
            'maps' => 'Локации', 'map' => 'Локация', 'zones' => 'Места выполнения', 'position' => 'Координаты',
            'optional' => 'Необязательная цель', 'foundInRaid' => 'Найдено в рейде', 'media' => 'Медиа', 'gallery' => 'Галерея',
            'walkthrough' => 'Прохождение', 'text' => 'Текст', 'steps' => 'Шаги', 'image' => 'Изображение', 'imageLink' => 'Ссылка на изображение',
            'taskRequirements' => 'Необходимые квесты', 'traderRequirements' => 'Требования торговцев',
            'wikiLink' => 'Ссылка на вики', 'kappaRequired' => 'Нужен для Каппы', 'story' => 'Сюжетный квест',
            'traders' => 'Торговцы', 'tasks' => 'Квесты', 'modes' => 'Режимы', 'url' => 'Ссылка', 'value' => 'Значение',
            'top' => 'Верхняя граница', 'bottom' => 'Нижняя граница', 'size' => 'Размер', 'outline' => 'Контур',
            'questItem' => 'Квестовый предмет', 'requiredKeys' => 'Необходимые ключи', 'neededKeys' => 'Ключи для прохождения',
            'keys' => 'Ключи', 'doc' => 'Документ', 'docName' => 'Название документа', 'mapName' => 'Название локации',
            'caption' => 'Описание точки', 'floor' => 'Этаж', 'task' => 'Квест', 'status' => 'Состояние',
            'skill' => 'Навык', 'station' => 'Модуль убежища', 'level' => 'Уровень', 'attributes' => 'Свойства',
            'usingWeapon' => 'Оружие', 'usingWeaponMods' => 'Модификации оружия', 'wearing' => 'Экипировка', 'notWearing' => 'Исключённая экипировка',
            'shotType' => 'Тип попадания', 'bodyParts' => 'Части тела', 'distance' => 'Дистанция',
            'timeFromHour' => 'Время с (час)', 'timeUntilHour' => 'Время до (час)', 'enemyHealthEffect' => 'Эффект у противника',
            'playerHealthEffect' => 'Эффект у игрока', 'targetNames' => 'Типы целей', 'x' => 'Координата X', 'y' => 'Координата Y', 'z' => 'Высота Z',
        ][$key] ?? $key;
    }
}
