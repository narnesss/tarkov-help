<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Поиск Orchid Relation с регистронезависимыми русскими и английскими названиями. */
final class ReferenceOptions extends ContentReference
{
    protected $table = 'content_references';
    public array $value = [];

    public function scopePicker(Builder $unused, string $kind, string $variant = 'default'): Collection
    {
        abort_unless(request()->user()?->hasAccess('wiki.content'), 403);
        $query = ContentReference::where('kind', $kind)->where('variant', $variant);
        if ($this->value !== []) { return $this->options($query->whereIn('id', $this->value)->get()); }
        $search = mb_strtolower(preg_replace('/[\s_-]+/u', ' ', trim((string) request()->input('search', ''))));
        return $this->options($query->where('active', true)->when($search !== '', fn ($q) => $q->whereRaw('instr(search_text, ?) > 0', [$search]))
            ->orderBy('label')->limit(min(30, max(1, (int) request()->input('chunk', 20))))->get());
    }

    private function options(Collection $records): Collection
    { return $records->map(fn (ContentReference $record) => new \Illuminate\Support\Fluent($record->only(['id', 'label']))); }
}
