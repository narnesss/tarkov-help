<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class Document extends Record
{
    protected $table = 'wiki_documents';
    protected $primaryKey = 'id';
    public function points() { return $this->belongsToMany(MapPoint::class,'wiki_point_documents','document_id','point_id')->wherePivot('archived',0); }
}
