<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\Quest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Сохраняет обычные поля формы, не теряя сложных условий в файле задания. */
final class QuestEditor
{
    public function save(Quest $quest, array $input): Quest
    {
        $traders = app(TraderCatalog::class);
        $data = Validator::make($input, [
            'revision' => ['nullable', 'string'],
            'quest_id' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/',
                Rule::notIn(['all', 'story', 'archive', 'trader']),
                Rule::unique('quests')->where('mode', $input['mode'] ?? '')->ignore($quest->id)],
            'mode' => ['required', Rule::in(['pve', 'regular'])],
            'name' => ['required', 'string', 'max:255'], 'trader_id' => ['required', Rule::in(array_keys($traders->options()))],
            'min_player_level' => ['required', 'integer', 'between:0,100'],
            'experience' => ['required', 'integer', 'min:0'], 'description' => ['nullable', 'string', 'max:100000'],
            'published' => ['required', 'boolean'], 'goals' => ['present', 'array', 'max:100'],
            'goals.*.id' => ['required', 'string', 'max:128', 'distinct'],
            'goals.*.description' => ['required', 'string', 'max:10000'],
            'goals.*.type' => ['required', 'string', 'max:128'],
            'goals.*.count' => ['nullable', 'integer', 'between:0,1000000'],
        ])->validate();
        if ($quest->exists && ($quest->quest_id !== $data['quest_id'] || $quest->mode !== $data['mode'])) {
            throw ValidationException::withMessages(['quest.quest_id' => 'ID и режим существующего квеста нельзя менять: они связаны с прогрессом.']);
        }
        $payload = $quest->payload; $oldGoals = collect($payload['objectives'] ?? [])->keyBy('id');
        if (array_diff($oldGoals->keys()->all(), array_column($data['goals'], 'id'))) {
            throw ValidationException::withMessages(['quest.goals' => 'Существующие ID целей нельзя удалять или менять.']);
        }
        ksort($data['goals'], SORT_NUMERIC);
        $payload['objectives'] = array_map(function (array $goal) use ($oldGoals): array {
            $result = [...($oldGoals->get($goal['id']) ?? []), 'id' => $goal['id'], 'description' => $goal['description'], 'type' => $goal['type']];
            if (isset($goal['count']) && $goal['count'] !== '') { $result['count'] = (int) $goal['count']; }
            elseif (array_key_exists('count', $goal)) { unset($result['count']); }
            return $result;
        }, array_values($data['goals']));
        $trader = $traders->find($data['trader_id']);
        $previousTrader = is_array($payload['trader'] ?? null) && ($payload['trader']['id'] ?? null) === $trader['id'] ? $payload['trader'] : [];
        $payload = [...$payload, 'id' => $data['quest_id'], 'name' => $data['name'],
            'trader' => [...$trader, ...$previousTrader, 'id' => $trader['id'], 'name' => $trader['name'], 'imageLink' => $trader['imageLink'] ?? null],
            'minPlayerLevel' => (int) $data['min_player_level'], 'experience' => (int) $data['experience'],
            'description' => $data['description'] ?? ''];
        if (isset($payload['questContent'])) { $payload['questContent']['experience'] = (int) $data['experience']; }
        $attributes = ['quest_id' => $data['quest_id'], 'mode' => $data['mode'], 'name' => $data['name'],
            'trader_id' => $data['trader_id'], 'published' => (bool) $data['published'], 'payload' => $payload];
        if (!$quest->exists) { $quest->fill($attributes)->save(); return $quest; }
        $this->update($quest, $attributes, $data['revision'] ?? '');
        return $quest;
    }

    /** Старая вкладка не должна затереть более свежую редакторскую правку. */
    public function update(Quest $quest, array $attributes, string $revision): void
    {
        if (!hash_equals($quest->revision(), $revision)) {
            throw ValidationException::withMessages(['revision' => 'Данные уже изменились. Обновите страницу и повторите правку.']);
        }
        $original = $quest->getRawOriginal('payload');
        $quest->fill($attributes); $changes = $quest->getDirty(); $changes['updated_at'] = now();
        if (Quest::whereKey($quest->id)->where('payload', $original)->update($changes) !== 1) {
            throw ValidationException::withMessages(['revision' => 'Данные уже изменились. Обновите страницу и повторите правку.']);
        }
        $quest->refresh();
    }
}
