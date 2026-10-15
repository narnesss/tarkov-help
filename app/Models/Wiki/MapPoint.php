<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class MapPoint extends Record
{
    protected $table = 'wiki_map_points';
    protected $primaryKey = 'id';
    public function location() { return $this->belongsTo(Location::class,'location_id'); }
    public function items() { return $this->belongsToMany(Item::class,'wiki_item_spawns','point_id','item_id')->withPivot(['amount','spawn_chance','notes'])->wherePivot('archived',0); }
    public function keys() { return $this->belongsToMany(Key::class,'wiki_key_usages','point_id','key_item_id')->withPivot(['usage_type','notes'])->wherePivot('archived',0); }
    public function documents() { return $this->belongsToMany(Document::class,'wiki_point_documents','point_id','document_id')->wherePivot('archived',0); }
    public function objectives() { return $this->belongsToMany(Objective::class,'wiki_objective_points','point_id','objective_id')->wherePivot('archived',0); }
}
