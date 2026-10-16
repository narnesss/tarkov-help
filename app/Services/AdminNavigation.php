<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Серверные вкладки: URL и PHP-фильтр определяют единственный отображаемый раздел. */
final class AdminNavigation
{
    public const MODES=['pve'=>'PvE','regular'=>'PvP'];
    public const QUEST_SECTIONS=['all'=>'Все квесты','standard'=>'Обычные','story'=>'Сюжетные','kappa'=>'Для Каппы','archived'=>'Архив'];
    public static function choice(string $field,array $options,string $default): string
    {
        $value=request()->query($field,$default);
        return Validator::make([$field=>$value],[$field=>['bail','required','string',Rule::in(array_keys($options))]],AdminInput::messages())->validate()[$field];
    }
    public static function tabs(string $route,array $options,string $field,string $active,array $parameters=[]): array
    {
        $links=[];foreach($options as $value=>$label){$links[]=['label'=>$label,'active'=>$value===$active,'url'=>route($route,[...$parameters,$field=>$value])];}return $links;
    }
}
