<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\Quest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Проверяет форму и сохраняет стабильные идентификаторы при редактировании. */
final class QuestEditor
{
    public function save(Quest $quest, array $input): Quest
    {
        if (isset($input['goals'])) {
            $goals = Validator::make($input, ['goals' => ['array', 'max:100'], 'goals.*' => ['array'],
                'goals.*.id' => ['required', 'string', 'max:128'], 'goals.*.description' => ['required', 'string', 'max:10000'],
                'goals.*.conditions' => ['required', 'string', 'max:100000']])->validate()['goals'];
            $input['objectives'] = json_encode(array_values(array_map(fn ($goal) => [
                ...$this->decode($goal['conditions'], 'goals'), 'id' => $goal['id'], 'description' => $goal['description'],
            ], $goals)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $data = Validator::make($input, [
            'quest_id' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/',
                Rule::notIn(['all', 'story', 'archive', 'trader']),
                Rule::unique('quests')->where('mode', $input['mode'] ?? '')->ignore($quest->id)],
            'mode' => ['required', Rule::in(['pve', 'regular'])],
            'name' => ['required', 'string', 'max:255'], 'trader_id' => ['required', 'string', 'max:128'],
            'trader_name' => ['required', 'string', 'max:255'], 'min_player_level' => ['required', 'integer', 'between:1,100'],
            'experience' => ['required', 'integer', 'min:0'], 'description' => ['nullable', 'string', 'max:100000'],
            'published' => ['required', 'boolean'], 'details' => ['required', 'string', 'max:1000000'],
            'objectives' => ['required', 'string', 'max:500000'], 'rewards' => ['required', 'string', 'max:500000'],
        ])->validate();
        if ($quest->exists && ($quest->quest_id !== $data['quest_id'] || $quest->mode !== $data['mode'])) {
            throw ValidationException::withMessages(['quest.quest_id' => 'ID и режим существующего квеста нельзя менять: они связаны с прогрессом.']);
        }
        $payload = $this->decode($data['details'], 'details');
        $objectives = $this->decode($data['objectives'], 'objectives');
        $rewards = $this->decode($data['rewards'], 'rewards');
        Validator::make(['objectives' => $objectives], [
            'objectives' => ['array'], 'objectives.*' => ['array'],
            'objectives.*.id' => ['required', 'string', 'max:128', 'distinct'],
            'objectives.*.description' => ['required', 'string', 'max:10000'],
        ])->validate();
        if (!array_is_list($objectives)) {
            throw ValidationException::withMessages(['quest.objectives' => 'Цели должны быть JSON-массивом.']);
        }
        if ($quest->exists) {
            $oldIds = array_column($quest->payload['objectives'] ?? [], 'id');
            if (array_diff($oldIds, array_column($objectives, 'id'))) {
                throw ValidationException::withMessages(['quest.objectives' => 'Существующие ID целей нельзя удалять или менять. Можно редактировать текст и добавлять цели.']);
            }
        }
        $payload = [...$payload, 'id' => $data['quest_id'], 'name' => $data['name'],
            'trader' => [...($quest->trader_id === $data['trader_id'] ? ($quest->payload['trader'] ?? []) : []),
                'id' => $data['trader_id'], 'name' => $data['trader_name']],
            'minPlayerLevel' => (int) $data['min_player_level'], 'experience' => (int) $data['experience'],
            'description' => $data['description'] ?? '', 'objectives' => $objectives, 'finishRewards' => $rewards];
        if (isset($payload['questContent'])) { $payload['questContent']['experience'] = (int) $data['experience']; }
        if (!$quest->exists || ($quest->payload['finishRewards'] ?? []) !== $rewards) { $payload['cmsRewards'] = true; }
        $quest->fill(['quest_id' => $data['quest_id'], 'mode' => $data['mode'], 'name' => $data['name'],
            'trader_id' => $data['trader_id'], 'published' => (bool) $data['published'], 'payload' => $payload])->save();
        return $quest;
    }

    public function decode(string $json, string $field): array
    {
        try { $data = json_decode($json, true, 128, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw ValidationException::withMessages(['quest.'.$field => 'Некорректный JSON.']); }
        if (!is_array($data)) { throw ValidationException::withMessages(['quest.'.$field => 'Ожидается JSON-объект или массив.']); }
        return $data;
    }
}
