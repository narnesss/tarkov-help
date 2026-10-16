<?php
declare(strict_types=1);
namespace App\Models\Wiki;

use App\Services\WikiSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\{Collection,Fluent};

/** Именованные варианты Orchid; таблица определяется только белым списком. */
final class Options extends Record
{
    protected $table = 'wiki_items';
    public array $value = [];
    public function scopePicker(Builder $unused,string $type): Collection
    {
        abort_unless(request()->user()?->hasAccess('wiki.content'),403);
        $query = app(WikiSchema::class)->query($type);
        if ($this->value !== []) {
            $order = array_flip(array_map('strval',$this->value));
            $rows = $query->whereIn(app(WikiSchema::class)->identity($type),$this->value)->get()->sortBy(fn ($row)=>$order[(string)$row->rowid]);
        } else {
            $search = WikiSchema::search(\App\Services\AdminInput::search(request()->input('search')));
            if(isset(app(WikiSchema::class)->columns($type)['active'])){$query->where('active',1);}
            $rows = $query->where('archived',0)->when($search !== '',fn ($q)=>$q->whereRaw('instr(search_text,?)>0',[$search]))
                ->orderBy('display_name')->limit(20)->get();
        }
        return $rows->map(fn ($row)=>new Fluent(['id'=>$row->rowid,'label'=>$row->display_name]));
    }
}
