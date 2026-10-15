<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Сохраняет строки проекции, не меняя стабильные ключи при повторном импорте. */
final class WikiStore
{
    public function __construct(private WikiSchema $schema) {}
    public function put(string $type,array $key,array $values): int
    {
        $table = $this->schema->table($type);$old = $this->schema->query($type)->where($key)->first();
        $attributes = [...$key,...$values];$all = [...($old?->getAttributes()??[]),...$attributes];
        $attributes['display_name'] = $this->schema->label($type,$all);
        $attributes['search_text'] = WikiSchema::search($attributes['display_name'].' '.($values['search_text'] ?? ''));
        if ($old === null) { return (int) DB::table($table)->insertGetId($attributes); }
        $dirty = array_filter($attributes,fn ($value,$field)=>(string)($old->$field ?? '') !== (string)($value ?? ''),ARRAY_FILTER_USE_BOTH);
        if ($dirty !== []) { $dirty['revision'] = $old->revision+1;$this->schema->record($type,$old->rowid)->update($dirty); }
        return (int)$old->rowid;
    }
    public function source(int $dataset,string $pointer,string $entity,int $id,string $format='object'): void
    {
        DB::table('wiki_sources')->updateOrInsert(['dataset_id'=>$dataset,'pointer'=>$pointer],[$entity.'_id'=>$id,'format'=>$format]);
    }
    public function issue(string $scope,string $id,string $field,string $message): void
    { DB::table('wiki_import_issues')->updateOrInsert(['scope'=>$scope,'external_id'=>$id,'field'=>$field],['message'=>$message]); }
}
