<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class Key extends Record
{
    protected $table = 'wiki_keys';
    protected $primaryKey = 'item_id';

    public function item() { return $this->belongsTo(Item::class,'item_id'); }
    public function usages() { return $this->belongsToMany(MapPoint::class,'wiki_key_usages','key_item_id','point_id')->withPivot(['usage_type','notes'])->wherePivot('archived',0); }
}
