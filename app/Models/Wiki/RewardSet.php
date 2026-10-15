<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class RewardSet extends Record
{
    protected $table = 'wiki_reward_sets';
    protected $primaryKey = 'id';
    public function quest() { return $this->belongsTo(Quest::class,'quest_id'); }
    public function items() { return $this->belongsToMany(Item::class,'wiki_reward_items','reward_set_id','item_id')->withPivot(['amount','found_in_raid','order_index'])->wherePivot('archived',0); }
}
