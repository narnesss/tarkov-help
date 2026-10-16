<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Models\Quest;
use App\Services\{QuestEditor, TraderCatalog};
use Illuminate\Http\Request;
use Orchid\Screen\Screen;
use Orchid\Screen\Actions\{Button, Link};
use Orchid\Screen\Fields\{Input, Select, TextArea, CheckBox, Matrix};
use Orchid\Support\Facades\{Layout, Toast};

/** Текстовая форма задания с выбором торговца и отдельными разделами условий/наград. */
final class QuestEditScreen extends Screen
{
    protected ?Quest $record = null;
    public function name(): string { return $this->record->exists ? 'Редактирование квеста' : 'Новый квест'; }
    public function permission(): array { return ['wiki.content']; }

    public function query(Quest $quest): array
    {
        $this->record = $quest; $payload = $quest->payload;
        return ['quest' => ['revision' => $quest->revision(),
            'quest_id' => $quest->quest_id, 'mode' => $quest->mode ?? \App\Services\AdminNavigation::choice('mode',\App\Services\AdminNavigation::MODES,'pve'), 'name' => $quest->name,
            'trader_id' => $quest->trader_id,
            'min_player_level' => $payload['minPlayerLevel'] ?? 1, 'experience' => $payload['experience'] ?? 0,
            'description' => $payload['description'] ?? '', 'published' => $quest->published ?? false,
            'goals' => array_map(fn ($goal) => ['id' => $goal['id'], 'description' => $goal['description'],
                'type' => $goal['type'] ?? 'custom', 'count' => $goal['count'] ?? null], $payload['objectives'] ?? [])]];
    }

    public function commandBar(): array
    { return [Button::make('Сохранить')->icon('bs.check')->method('save'), Link::make('К списку')->route('platform.quests')]; }

    public function layout(): array
    {
        $types = ['visit' => 'Посетить место', 'findItem' => 'Найти предмет', 'giveItem' => 'Передать предмет',
            'findQuestItem' => 'Найти квестовый предмет', 'giveQuestItem' => 'Передать квестовый предмет',
            'shoot' => 'Уничтожить цель', 'extract' => 'Выйти с локации', 'mark' => 'Установить маяк',
            'plantItem' => 'Разместить предмет', 'buildWeapon' => 'Собрать оружие', 'skill' => 'Развить навык', 'custom' => 'Другая цель'];
        foreach ($this->record->payload['objectives'] ?? [] as $goal) {
            if (isset($goal['type']) && !isset($types[$goal['type']])) { $types[$goal['type']] = $goal['type']; }
        }
        $sections = [];
        if ($this->record->exists) {
            $domain=\Illuminate\Support\Facades\DB::table('wiki_quests')->where('legacy_quest_id',$this->record->id)->value('id');
            if($domain){$sections[]=Link::make('Предметные связи: цели, точки, ключи и награды')->route('platform.wiki.edit',['type'=>'quests','record'=>$domain]);}
            foreach (['/objectives' => 'Подробные условия целей', '/finishRewards' => 'Награды', '/questContent' => 'Прохождение и материалы', '' => 'Остальные поля задания'] as $section => $title) {
                $sections[] = Link::make($title)->route('platform.quests.fields', ['quest' => $this->record->id, 'section' => $section]);
            }
        }
        return [Layout::rows([
            Input::make('quest.revision')->type('hidden'),
            Input::make('quest.quest_id')->type('hidden'),
            Select::make('quest.mode')->title('Режим')->options(['pve' => 'PVE', 'regular' => 'PVP'])->disabled($this->record->exists),
            Input::make('quest.name')->title('Название')->required()->maxlength(255)->set('data-admin-text','1'),
            Select::make('quest.trader_id')->title('Торговец')->options(app(TraderCatalog::class)->options())->empty('Выберите торговца')->required(),
            Input::make('quest.min_player_level')->title('Минимальный уровень')->type('number')->min(0)->max(100)->required(),
            Input::make('quest.experience')->title('Опыт')->type('number')->min(0)->required(),
            TextArea::make('quest.description')->title('Описание')->rows(5)->maxlength(100000)->set('data-admin-text','1'),
            CheckBox::make('quest.published')->title('Опубликован')->sendTrueOrFalse(),
        ])->title('Основные данные'), Layout::rows([
            Matrix::make('quest.goals')->title('Цели задания')->columns(['' => 'id', 'Описание' => 'description', 'Действие' => 'type', 'Количество' => 'count'])
                ->fields(['id' => Input::make()->type('hidden'), 'description' => TextArea::make()->rows(4)->required()->maxlength(10000)->set('data-admin-text','1'),
                    'type' => Select::make()->options($types), 'count' => Input::make()->type('number')->min(0)->max(1000000)->step(1)])
                ->addRowLabel('Добавить цель')->removableRows(false)->maxRows(100)
                ->help('Идентификаторы создаются автоматически. После сохранения откройте «Подробные условия целей», чтобы выбрать предметы, локации и ключи по названиям.'),
            ...$sections,
        ])->title('Цели, награды и прохождение'),Layout::view('platform.content-validation')];
    }

    public function save(Request $request, Quest $quest, QuestEditor $editor)
    {
        $input = $request->validate(['quest'=>['required','array']])['quest']; $input['goals'] ??= [];
        if ($quest->exists) { $input['mode'] = $quest->mode; }
        try { $editor->save($quest, $input); }
        catch (\Illuminate\Validation\ValidationException $error) {
            $errors = [];
            foreach ($error->errors() as $field => $messages) { $errors[str_starts_with($field, 'quest.') ? $field : 'quest.'.$field] = $messages; }
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
        Toast::info('Квест сохранён. Изменения доступны на сайте после обновления страницы.');
        return redirect()->route('platform.quests.edit', $quest);
    }
}
