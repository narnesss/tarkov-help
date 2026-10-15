<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class ObjectiveGroup extends Record
{
    protected $table = 'wiki_objective_groups';
    protected $primaryKey = 'id';
    public function objective() { return $this->belongsTo(Objective::class,'objective_id'); }
    public function parent() { return $this->belongsTo(self::class,'parent_id'); }
    public function children() { return $this->hasMany(self::class,'parent_id'); }
    public function items() { return $this->belongsToMany(Item::class,'wiki_objective_items','group_id','item_id')->withPivot(['role','amount','found_in_raid'])->wherePivot('archived',0); }
}
