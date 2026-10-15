<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class Location extends Record
{
    protected $table = 'wiki_locations';
    protected $primaryKey = 'id';
    public function maps() { return $this->hasMany(Map::class,'location_id'); }
    public function points() { return $this->hasMany(MapPoint::class,'location_id'); }
    public function objectives() { return $this->belongsToMany(Objective::class,'wiki_objective_locations','location_id','objective_id')->wherePivot('archived',0); }
}
