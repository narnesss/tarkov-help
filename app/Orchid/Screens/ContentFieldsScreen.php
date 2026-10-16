<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Services\{AdminInput, ContentFields, ContentLinks};
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
    protected array $context = [];
    abstract protected function destination(string $section, int $page = 1): string;
    abstract protected function backUrl(): string;
    abstract protected function persist(Request $request, array $document): void;
    abstract protected function reloadRecord(Request $request): void;

    public function permission(): array { return ['wiki.content']; }
    public function description(): string { return 'Откройте нужный раздел и заполните отдельные поля.'; }

    protected function loadFields(array $document, string $revision): array
    {
        $this->document = $document; $this->revision = $revision;
        $query=\Illuminate\Support\Facades\Validator::make(request()->query(),['section'=>['sometimes','string','max:2000'],'page'=>['sometimes','integer','min:1','max:1000000']])->validate();
        $this->section = $query['section']??''; $this->page = (int)($query['page']??1);
        $values = [];
        foreach (app(ContentFields::class)->entries($document, $this->section, $this->page, $this->context) as $entry) {
            if ($entry['readonly']) { continue; }
            if ($entry['reference'] !== null) { $values[$entry['key']] = app(ContentLinks::class)->selected($entry['value'], $entry['reference']); }
            elseif (!is_array($entry['value'])) { $values[$entry['key']] = is_bool($entry['value']) ? (int) $entry['value'] : $entry['value']; }
        }
        return ['fields' => $values, 'revision' => $revision];
    }

    public function commandBar(): array
    { return [Button::make('Сохранить поля')->method('save', ['section' => $this->section, 'page' => $this->page]), Link::make('К основной форме')->href($this->backUrl())]; }

    public function layout(): array
    {
        $service = app(ContentFields::class); $fields = [Input::make('revision')->type('hidden')];
        if ($this->section !== '') {
            $parent = substr($this->section, 0, (int) strrpos($this->section, '/'));
            $fields[] = Link::make('← На уровень выше')->href($this->destination($parent));
        }
        foreach ($service->entries($this->document, $this->section, $this->page, $this->context) as $entry) {
            if ($entry['readonly']) { continue; }
            if ($entry['reference'] !== null) {
                $fields[] = Input::make('emptyReferences.'.$entry['key'])->type('hidden')->value('1');
                $fields[] = app(ContentLinks::class)->field('fields.'.$entry['key'], $entry['reference'])->title($entry['label']);
                continue;
            }
            if (is_array($entry['value'])) {
                $fields[] = Link::make($entry['label'].' ('.count($entry['value']).')')->href($this->destination($entry['path']));
                continue;
            }
            $name = 'fields.'.$entry['key']; $value = $entry['value'];
            $coordinate = ($this->context['source'] ?? '') === 'spawns.json' && preg_match('~^/\d+/(x|y|x2|y2)$~', $entry['path']);
            $field = $coordinate ? Input::make($name)->type('number')->step('any')->min(0)->max(100)->help('Проценты от размера изображения, от 0 до 100.')
                : (is_bool($value) ? Select::make($name)->options([0 => 'Нет', 1 => 'Да'])
                : (is_int($value) || is_float($value) ? Input::make($name)->type('number')->step(is_int($value) ? '1' : 'any')
                    : TextArea::make($name)->rows(is_string($value) && mb_strlen($value) > 150 ? 5 : 2)));
            $field->title($entry['label']);
            if(!$coordinate&&!is_bool($value)&&!is_int($value)&&!is_float($value)){$field->set('data-admin-text','1')->set('data-admin-url',AdminInput::urlField($entry['path'])?'1':'0')->maxlength(AdminInput::urlField($entry['path'])?2048:1000000);}
            if ($entry['readonly']) { $field->readonly()->disabled()->help('Стабильный идентификатор.'); }
            $fields[] = $field;
        }
        $count = count($service->section($this->document, $this->section));
        if ($this->page > 1) { $fields[] = Link::make('← Предыдущие поля')->href($this->destination($this->section, $this->page - 1)); }
        if ($count > $this->page * ContentFields::PAGE_SIZE) { $fields[] = Link::make('Следующие поля →')->href($this->destination($this->section, $this->page + 1)); }
        $layouts = [Layout::rows($fields)->title($this->section === '' ? 'Разделы и поля' : implode(' / ', array_map(fn ($part) => ctype_digit($part) ? 'Запись '.((int) $part + 1) : (preg_match('/^[a-f0-9]{24,64}$/', $part) ? 'Запись' : $service->label($part)), explode('/', trim($this->section, '/')))))];
        $additions = $service->additions($this->document, $this->section, $this->context);
        $sectionLink = app(ContentLinks::class)->specification($this->document, $this->section, $service->section($this->document, $this->section), $this->context, false);
        if ($sectionLink !== null && $sectionLink['multiple']) {
            $layouts[] = Layout::rows([
                app(ContentLinks::class)->field('addition.reference', [...$sectionLink, 'multiple' => false])->title('Добавить по названию'),
                Button::make('Добавить выбранное')->method('add', ['section' => $this->section, 'page' => $this->page]),
            ])->title('Добавление в список');
        } elseif ($additions !== [] && $sectionLink === null) {
            $layouts[] = Layout::rows([
                Select::make('addition.preset')->title('Что добавить')->options(array_map(fn ($preset) => $preset['label'], $additions)),
                TextArea::make('addition.value')->title('Текст новой записи')->rows(2)->maxlength(100000)->set('data-admin-text','1')->set('data-admin-url',AdminInput::urlField($this->section.'/0')?'1':'0')->help('Для текстовых списков и названий новых записей справочника.'),
                Button::make('Добавить')->method('add', ['section' => $this->section, 'page' => $this->page]),
            ])->title('Добавление данных');
        }
        return [...$layouts,Layout::view('platform.content-validation')];
    }

    public function save(Request $request)
    {
        $this->reloadRecord($request);
        $request->validate(['revision' => ['required', 'string'], 'fields' => ['sometimes', 'array']]);
        $document = app(ContentFields::class)->apply($this->document, $this->section, $this->page, $this->submittedFields($request), $this->context);
        $this->persist($request, $document); Toast::info('Поля сохранены.');
        return redirect($this->destination($this->section, $this->page));
    }

    public function add(Request $request)
    {
        $this->reloadRecord($request);
        $request->validate(['revision' => ['required', 'string'], 'fields'=>['sometimes','array'], 'addition' => ['required', 'array']]);
        $document = app(ContentFields::class)->apply($this->document, $this->section, $this->page, $this->submittedFields($request), $this->context);
        $document = app(ContentFields::class)->append($document, $this->section, $request->input('addition'), $this->context);
        $this->persist($request, $document); Toast::info('Запись добавлена.');
        $count = count(app(ContentFields::class)->section($document, $this->section));
        return redirect($this->destination($this->section, max(1, (int) ceil($count / ContentFields::PAGE_SIZE))));
    }

    private function submittedFields(Request $request): array
    {
        $fields = $request->input('fields', []);
        foreach (app(ContentFields::class)->entries($this->document, $this->section, $this->page, $this->context) as $entry) {
            if ($entry['reference'] !== null && $request->input('emptyReferences.'.$entry['key']) === '1' && !array_key_exists($entry['key'], $fields)) {
                $fields[$entry['key']] = $entry['reference']['multiple'] ? [] : null;
            }
        }
        return $fields;
    }
}
