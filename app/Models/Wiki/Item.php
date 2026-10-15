<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class Item extends Record
{
    protected $table = 'wiki_items';
    protected $primaryKey = 'id';
    public function key() { return $this->hasOne(Key::class,'item_id'); }
    public function spawns() { return $this->belongsToMany(MapPoint::class,'wiki_item_spawns','item_id','point_id')->withPivot(['amount','spawn_chance','notes'])->wherePivot('archived',0); }
    public function objectiveGroups() { return $this->belongsToMany(ObjectiveGroup::class,'wiki_objective_items','item_id','group_id')->withPivot(['role','amount','found_in_raid'])->wherePivot('archived',0); }
}
