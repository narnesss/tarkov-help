<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Services\{ContentFields, TraderCatalog};
use Illuminate\Http\Request;
use Orchid\Screen\Screen;
use Orchid\Screen\Actions\{Button, Link};
use Orchid\Screen\Fields\{Input, Select, TextArea};
use Orchid\Support\Facades\{Layout, Toast};

/** Общая форма вложенных данных: разделы, текст, числа и выбор да/нет. */
abstract class ContentFieldsScreen extends Screen
{
    protected array $document = [];
    protected string $section = '';
    protected int $page = 1;
    protected string $revision = '';
    abstract protected function destination(string $section, int $page = 1): string;
    abstract protected function backUrl(): string;
    abstract protected function persist(Request $request, array $document): void;
    abstract protected function reloadRecord(Request $request): void;

    public function permission(): array { return ['wiki.content']; }
    public function description(): string { return 'Откройте нужный раздел и заполните отдельные поля.'; }

    protected function loadFields(array $document, string $revision): array
    {
        $this->document = $document; $this->revision = $revision;
        $this->section = (string) request()->query('section', ''); $this->page = max(1, (int) request()->query('page', 1));
        $values = [];
        foreach (app(ContentFields::class)->entries($document, $this->section, $this->page) as $entry) {
            if (!is_array($entry['value'])) { $values[$entry['key']] = is_bool($entry['value']) ? (int) $entry['value'] : $entry['value']; }
        }
        return ['fields' => $values, 'revision' => $revision];
    }

    public function commandBar(): array
    { return [Button::make('Сохранить поля')->method('save'), Link::make('К основной форме')->href($this->backUrl())]; }

    public function layout(): array
    {
        $service = app(ContentFields::class); $traders = app(TraderCatalog::class); $fields = [Input::make('revision')->type('hidden')];
        if ($this->section !== '') {
            $parent = substr($this->section, 0, (int) strrpos($this->section, '/'));
            $fields[] = Link::make('← На уровень выше')->href($this->destination($parent));
        }
        foreach ($service->entries($this->document, $this->section, $this->page) as $entry) {
            if (is_array($entry['value'])) {
                $fields[] = Link::make($entry['label'].' ('.count($entry['value']).')')->href($this->destination($entry['path']));
                continue;
            }
            $name = 'fields.'.$entry['key']; $value = $entry['value'];
            $trader = str_ends_with($entry['path'], '/trader') && is_string($value) && $traders->find($value) !== null;
            $field = $trader ? Select::make($name)->options($traders->options()) : (is_bool($value) ? Select::make($name)->options([0 => 'Нет', 1 => 'Да'])
                : (is_int($value) || is_float($value) ? Input::make($name)->type('number')->step(is_int($value) ? '1' : 'any')
                    : TextArea::make($name)->rows(is_string($value) && mb_strlen($value) > 150 ? 5 : 2)));
            $field->title($entry['label']);
            if ($entry['readonly']) { $field->readonly()->disabled()->help('Стабильный идентификатор.'); }
            $fields[] = $field;
        }
        $count = count($service->section($this->document, $this->section));
        if ($this->page > 1) { $fields[] = Link::make('← Предыдущие поля')->href($this->destination($this->section, $this->page - 1)); }
        if ($count > $this->page * ContentFields::PAGE_SIZE) { $fields[] = Link::make('Следующие поля →')->href($this->destination($this->section, $this->page + 1)); }
        return [Layout::rows($fields)->title($this->section === '' ? 'Разделы и поля' : implode(' / ', array_map(fn ($part) => $service->label($part), explode('/', trim($this->section, '/'))))),
            Layout::rows([
                Input::make('addition.name')->title('Имя нового поля')->help('Для добавления записи в список оставьте пустым.'),
                Select::make('addition.type')->title('Что добавить')->options(['text' => 'Текст', 'number' => 'Число', 'boolean' => 'Да / нет',
                    'group' => 'Раздел', 'list' => 'Список', 'copy' => 'Запись по образцу первой записи']),
                TextArea::make('addition.value')->title('Текст нового поля')->rows(3),
                Button::make('Добавить поле или запись')->method('add'),
            ])->title('Добавление данных')];
    }

    public function save(Request $request)
    {
        $this->reloadRecord($request);
        $request->validate(['revision' => ['required', 'string'], 'fields' => ['sometimes', 'array']]);
        $document = app(ContentFields::class)->apply($this->document, $this->section, $this->page, $request->input('fields', []));
        $this->persist($request, $document); Toast::info('Поля сохранены.');
        return redirect($this->destination($this->section, $this->page));
    }

    public function add(Request $request)
    {
        $this->reloadRecord($request);
        $request->validate(['revision' => ['required', 'string'], 'addition' => ['required', 'array']]);
        $document = app(ContentFields::class)->apply($this->document, $this->section, $this->page, $request->input('fields', []));
        $document = app(ContentFields::class)->append($document, $this->section, $request->input('addition'));
        $this->persist($request, $document); Toast::info('Запись добавлена.');
        $count = count(app(ContentFields::class)->section($document, $this->section));
        return redirect($this->destination($this->section, max(1, (int) ceil($count / ContentFields::PAGE_SIZE))));
    }
}
