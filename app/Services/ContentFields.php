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

    public function entries(array $document, string $path, int $page): array
    {
        $entries = [];
        foreach ($this->section($document, $path) as $key => $value) {
            $child = $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            $label = is_int($key) ? 'Запись '.($key + 1) : $this->label((string) $key);
            if (is_array($value)) {
                $title = $value['name'] ?? $value['title'] ?? $value['description'] ?? null;
                if (is_string($title) && $title !== '') { $label .= ' — '.mb_substr($title, 0, 100); }
            }
            $entries[] = ['path' => $child, 'key' => hash('sha256', $child), 'label' => $label,
                'value' => $value, 'readonly' => $key === 'id' || $key === 'quest_id'];
        }
        return array_slice($entries, (max(1, $page) - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
    }

    public function apply(array $document, string $path, int $page, array $fields): array
    {
        $known = [];
        foreach ($this->entries($document, $path, $page) as $entry) {
            if (!is_array($entry['value']) && !$entry['readonly']) { $known[$entry['key']] = $entry; }
        }
        if (array_diff_key($fields, $known)) { throw ValidationException::withMessages(['fields' => 'Недопустимые поля формы. Обновите страницу.']); }
        foreach ($fields as $key => $input) {
            $entry = $known[$key]; $original = $entry['value'];
            $rules = is_bool($original) ? ['required', 'boolean'] : (is_int($original)
                ? ['required', 'integer'] : (is_float($original) ? ['required', 'numeric'] : ['nullable', 'string', 'max:1000000']));
            if (str_ends_with($entry['path'], '/trader') && is_string($original) && app(TraderCatalog::class)->find($original) !== null) {
                $rules = ['required', \Illuminate\Validation\Rule::in(array_keys(app(TraderCatalog::class)->options()))];
            }
            Validator::make(['fields' => [$key => $input]], ['fields.'.$key => $rules])->validate();
            $value = match (true) { is_bool($original) => (bool) $input, is_int($original) => (int) $input,
                is_float($original) => (float) $input, $original === null && ($input === '' || $input === null) => null,
                default => (string) ($input ?? '') };
            $document = $this->replace($document, $entry['path'], $value);
        }
        return $document;
    }

    public function append(array $document, string $path, array $input): array
    {
        $data = Validator::make($input, ['name' => ['nullable', 'string', 'max:128'],
            'type' => ['required', 'in:text,number,boolean,group,list,copy'], 'value' => ['nullable', 'string', 'max:100000']])->validate();
        $section = $this->section($document, $path);
        $value = match ($data['type']) { 'group', 'list' => [], 'number' => 0, 'boolean' => false,
            'copy' => $this->blank($section === [] ? [] : reset($section)), default => $data['value'] ?? '' };
        if (array_is_list($section) && ($section !== [] || empty($data['name']))) { $section[] = $value; }
        else {
            $key = trim($data['name'] ?? '');
            if ($key === '' || str_contains($key, '/') || array_key_exists($key, $section)) {
                throw ValidationException::withMessages(['addition.name' => 'Введите новое имя поля без косой черты.']);
            }
            $section[$key] = $value;
        }
        return $path === '' ? $section : $this->replace($document, $path, $section);
    }

    private function blank(mixed $value, string|int $key = ''): mixed
    {
        if ($key === 'id') { return 'custom-'.bin2hex(random_bytes(12)); }
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

    private function parts(string $path): array
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
            'maps' => 'Локации', 'map' => 'Локация', 'zones' => 'Места выполнения', 'position' => 'Координаты',
            'optional' => 'Необязательная цель', 'foundInRaid' => 'Найдено в рейде', 'media' => 'Медиа', 'gallery' => 'Галерея',
            'walkthrough' => 'Прохождение', 'text' => 'Текст', 'steps' => 'Шаги', 'image' => 'Изображение', 'imageLink' => 'Ссылка на изображение',
            'taskRequirements' => 'Необходимые квесты', 'traderRequirements' => 'Требования торговцев',
            'wikiLink' => 'Ссылка на вики', 'kappaRequired' => 'Нужен для Каппы', 'story' => 'Сюжетный квест',
            'traders' => 'Торговцы', 'tasks' => 'Квесты', 'modes' => 'Режимы', 'url' => 'Ссылка', 'value' => 'Значение',
            'top' => 'Верхняя граница', 'bottom' => 'Нижняя граница', 'size' => 'Размер', 'outline' => 'Контур',
        ][$key] ?? $key;
    }
}
