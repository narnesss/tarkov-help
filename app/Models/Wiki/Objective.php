<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class Objective extends Record
{
    protected $table = 'wiki_objectives';
    protected $primaryKey = 'id';
    public function quest() { return $this->belongsTo(Quest::class,'quest_id'); }
    public function groups() { return $this->hasMany(ObjectiveGroup::class,'objective_id'); }
    public function points() { return $this->belongsToMany(MapPoint::class,'wiki_objective_points','objective_id','point_id')->wherePivot('archived',0); }
    public function locations() { return $this->belongsToMany(Location::class,'wiki_objective_locations','objective_id','location_id')->wherePivot('archived',0); }
    public function keys() { return $this->belongsToMany(Key::class,'wiki_objective_keys','objective_id','key_item_id')->withPivot(['optional','notes'])->wherePivot('archived',0); }
}
