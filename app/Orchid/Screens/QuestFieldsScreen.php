<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Models\Quest;
use App\Services\QuestEditor;
use Illuminate\Http\Request;

/** Подробные условия, награды и материалы квеста редактируются обычными полями. */
final class QuestFieldsScreen extends ContentFieldsScreen
{
    protected Quest $record;
    private const MAIN = ['id', 'name', 'trader', 'minPlayerLevel', 'experience', 'description'];
    public function name(): string { return 'Поля квеста: '.$this->record->name; }
    public function query(Quest $quest): array
    {
        $this->record = $quest;
        return $this->loadFields(array_diff_key($quest->payload, array_flip(self::MAIN))
            + ['objectives' => [], 'finishRewards' => [], 'questContent' => []], $quest->revision());
    }
    protected function destination(string $section, int $page = 1): string
    { return route('platform.quests.fields', ['quest' => $this->record->id, 'section' => $section, 'page' => $page]); }
    protected function backUrl(): string { return route('platform.quests.edit', $this->record); }
    protected function reloadRecord(Request $request): void
    {
        $record = $request->route('quest');
        $this->query($record instanceof Quest ? $record : Quest::findOrFail($record));
    }
    protected function persist(Request $request, array $document): void
    {
        abort_if(array_intersect_key($document, array_flip(self::MAIN)) !== [], 422);
        $payload = [...$this->record->payload, ...$document];
        if (($this->record->payload['finishRewards'] ?? []) !== ($payload['finishRewards'] ?? [])) { $payload['cmsRewards'] = true; }
        app(QuestEditor::class)->update($this->record, ['payload' => $payload], $request->string('revision')->toString());
    }
}
