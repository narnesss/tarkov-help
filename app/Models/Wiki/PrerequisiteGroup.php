<?php
declare(strict_types=1);
namespace App\Models\Wiki;

/** Предметная сущность и типизированные связи Eloquent. */
final class PrerequisiteGroup extends Record
{
    protected $table = 'wiki_prerequisite_groups';
    protected $primaryKey = 'id';
    public function quest() { return $this->belongsTo(Quest::class,'quest_id'); }
    public function prerequisites() { return $this->belongsToMany(Quest::class,'wiki_quest_prerequisites','group_id','prerequisite_quest_id')->withPivot(['status','hard_requirement'])->wherePivot('archived',0); }
    public function children() { return $this->hasMany(self::class,'parent_id'); }
}
