<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class Trader extends Record
{
    protected $table = 'wiki_traders';
    protected $primaryKey = 'id';
    public function quests() { return $this->hasMany(Quest::class,'trader_id'); }
}
