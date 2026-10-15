<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class MapLayer extends Record
{
    protected $table = 'wiki_map_layers';
    protected $primaryKey = 'id';
    public function map() { return $this->belongsTo(Map::class,'map_id'); }
}
