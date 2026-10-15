<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class Quest extends Record
{
    protected $table = 'wiki_quests';
    protected $primaryKey = 'id';
    public function trader() { return $this->belongsTo(Trader::class,'trader_id'); }
    public function objectives() { return $this->hasMany(Objective::class,'quest_id')->orderBy('order_index'); }
    public function prerequisiteGroups() { return $this->hasMany(PrerequisiteGroup::class,'quest_id'); }
    public function rewards() { return $this->hasMany(RewardSet::class,'quest_id'); }
    public function legacy() { return $this->belongsTo(\App\Models\Quest::class,'legacy_quest_id'); }
}
