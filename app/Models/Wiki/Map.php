<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class Map extends Record
{
    protected $table = 'wiki_maps';
    protected $primaryKey = 'id';
    public function location() { return $this->belongsTo(Location::class,'location_id'); }
    public function layers() { return $this->hasMany(MapLayer::class,'map_id'); }
}
