<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Models\Quest;
use App\Services\QuestEditor;
use Illuminate\Http\Request;
use Orchid\Screen\Screen;
use Orchid\Screen\Actions\{Button, Link};
use Orchid\Screen\Fields\{Input, Select, TextArea, CheckBox, Matrix};
use Orchid\Support\Facades\{Layout, Toast};

/** Форма задания: основные поля и полный JSON сложных условий/награды/медиа. */
final class QuestEditScreen extends Screen
{
    protected ?Quest $record = null;

    public function name(): string { return $this->record->exists ? 'Редактирование квеста' : 'Новый квест'; }
    public function permission(): array { return ['wiki.content']; }

    public function query(Quest $quest): array
    {
        $this->record = $quest; $payload = $quest->payload ?? [];
        $json = fn ($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['quest' => [
            'quest_id' => $quest->quest_id, 'mode' => $quest->mode ?? 'pve', 'name' => $quest->name,
            'trader_id' => $payload['trader']['id'] ?? '', 'trader_name' => $payload['trader']['name'] ?? '',
            'min_player_level' => $payload['minPlayerLevel'] ?? 1, 'experience' => $payload['experience'] ?? 0,
            'description' => $payload['description'] ?? '', 'published' => $quest->published ?? false,
            'goals' => array_map(fn ($goal) => ['id' => $goal['id'], 'description' => $goal['description'],
                'conditions' => $json((object) array_diff_key($goal, ['id' => true, 'description' => true]))], $payload['objectives'] ?? []),
            'rewards' => $json($payload['finishRewards'] ?? (object) []),
            'details' => $json((object) array_diff_key($payload, array_flip(['id', 'name', 'trader', 'minPlayerLevel', 'experience', 'description', 'objectives', 'finishRewards']))),
        ]];
    }

    public function commandBar(): array
    { return [Button::make('Сохранить')->icon('bs.check')->method('save'), Link::make('К списку')->route('platform.quests')]; }

    public function layout(): array
    {
        return [Layout::rows([
            Input::make('quest.quest_id')->title('ID квеста')->required()->readonly($this->record->exists)
                ->help('Стабильный ID. После создания не меняется.'),
            Select::make('quest.mode')->title('Режим')->options(['pve' => 'PVE', 'regular' => 'PVP'])->disabled($this->record->exists),
            Input::make('quest.name')->title('Название')->required(),
            Input::make('quest.trader_id')->title('ID торговца')->required(),
            Input::make('quest.trader_name')->title('Имя торговца')->required(),
            Input::make('quest.min_player_level')->title('Минимальный уровень')->type('number')->min(1)->max(100)->required(),
            Input::make('quest.experience')->title('Опыт')->type('number')->min(0)->required(),
            TextArea::make('quest.description')->title('Описание')->rows(5),
            CheckBox::make('quest.published')->title('Опубликован')->sendTrueOrFalse(),
        ])->title('Основные данные'), Layout::rows([
            Matrix::make('quest.goals')->title('Цели задания')->columns(['ID цели' => 'id', 'Описание' => 'description', 'Условия (JSON)' => 'conditions'])
                ->fields(['id' => Input::make()->required(), 'description' => TextArea::make()->rows(4)->required(),
                    'conditions' => TextArea::make()->rows(6)->value('{}')])
                ->addRowLabel('Добавить цель')->removableRows(false)->maxRows(100)
                ->help('Каждая цель — отдельная строка. В условиях: type, count, map, предметы, координаты и ограничения. Существующие ID сохраняйте.'),
            TextArea::make('quest.rewards')->title('Награды (JSON)')->rows(12)->required(),
            TextArea::make('quest.details')->title('Дополнительные данные (JSON)')->rows(20)->required()
                ->help('Условия открытия, questContent (тексты прохождения), медиа, карты, ветки сюжета и другие поля.'),
        ])->title('Цели, награды и прохождение')];
    }

    public function save(Request $request, Quest $quest, QuestEditor $editor)
    {
        $input = $request->input('quest', []);
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
