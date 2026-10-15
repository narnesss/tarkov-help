<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Models\ContentDataset;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Справочники в файлах; в форме только отдельные значения и разделы. */
final class DatasetEditScreen extends ContentFieldsScreen
{
    protected ?ContentDataset $record = null;
    public function name(): string { return 'Данные сайта: '.$this->record->path; }
    public function query(ContentDataset $dataset): array
    {
        $this->record = $dataset;
        $this->context = ['source' => $dataset->path, 'mode' => str_contains($dataset->path, 'pve') ? 'pve' : 'regular'];
        return $this->loadFields($dataset->content(), $dataset->revision());
    }
    protected function destination(string $section, int $page = 1): string
    { return route('platform.datasets.edit', ['dataset' => $this->record->id, 'section' => $section, 'page' => $page]); }
    protected function backUrl(): string { return route('platform.datasets'); }
    protected function reloadRecord(Request $request): void
    {
        $record = $request->route('dataset');
        $this->query($record instanceof ContentDataset ? $record : ContentDataset::findOrFail($record));
    }
    protected function persist(Request $request, array $document): void
    {
        if (!hash_equals($this->record->revision(), (string) $request->input('revision'))) {
            throw ValidationException::withMessages(['revision' => 'Данные уже изменились. Обновите страницу и повторите правку.']);
        }
        $original = $this->record->getRawOriginal('payload');
        $this->record->setContent($document);
        \Illuminate\Support\Facades\DB::transaction(function () use ($original): void {
            if (ContentDataset::whereKey($this->record->id)->where('payload', $original)
                ->update(['payload' => $this->record->payload, 'updated_at' => now()]) !== 1) {
                throw ValidationException::withMessages(['revision' => 'Данные уже изменились. Обновите страницу и повторите правку.']);
            }
            $this->record->refresh(); app(\App\Services\ReferenceIndexer::class)->dataset($this->record);
        });
    }
}
