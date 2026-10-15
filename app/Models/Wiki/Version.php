<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class Version extends Record
{
    protected $table = 'wiki_versions';
    protected $primaryKey = 'id';
    public function quests() { return $this->hasMany(Quest::class,'version_id'); }
    public function maps() { return $this->hasMany(Map::class,'version_id'); }
}
