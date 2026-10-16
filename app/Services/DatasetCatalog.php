<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\ContentDataset;
use Illuminate\Support\Facades\Cache;

/** Понятные названия и логические разделы справочников без переименования файлов. */
final class DatasetCatalog
{
    public const GROUPS=['all'=>'Все разделы','quests'=>'Квесты','items'=>'Предметы','bosses'=>'Боссы','achievements'=>'Достижения',
        'crafts'=>'Крафты','hideout'=>'Убежище','trading'=>'Торговля','maps'=>'Интерактивные карты','documents'=>'Документы','references'=>'Справочники','service'=>'Служебные данные'];
    public const MAPS=['customs'=>'Таможня','factory'=>'Завод','ground-zero'=>'Эпицентр','icebreaker'=>'Ледокол','interchange'=>'Развязка',
        'lighthouse'=>'Маяк','reserve'=>'Резерв','shoreline'=>'Берег','streets-of-tarkov'=>'Улицы Таркова','terminal'=>'Терминал','the-lab'=>'Лаборатория','the-labyrinth'=>'Лабиринт','woods'=>'Лес'];
    public static function metadata(string $path): array
    {
        $mode=str_contains($path,'pve')?'PvE':(str_contains($path,'regular')||preg_match('~^raid/(?:'.implode('|',array_keys(self::MAPS)).')\.json$~',$path)?'PvP':'Общие');
        if(preg_match('~^raid/(?:pve/)?([a-z-]+)\.json$~',$path,$match)&&isset(self::MAPS[$match[1]])){return ['title'=>'Точки карты: '.self::MAPS[$match[1]],'group'=>'maps','mode'=>$mode];}
        $titles=[
            'home.json'=>['Главная страница и навигация','references'],'maps.json'=>['Карты документов','documents'],'docs.json'=>['Каталог документов','documents'],'spawns.json'=>['Точки документов','documents'],
            'guide/bosses.json'=>['Боссы и места появления','bosses'],'guide/btr.json'=>['БТР и услуги','references'],
            'guide/gunsmith.json'=>['Оружейник: сборки оружия','quests'],'guide/items-pve.json'=>['Каталог предметов','items'],'guide/items-regular.json'=>['Каталог предметов','items'],
            'guide/quests-pve.json'=>['Каталог квестов','quests'],'guide/quests-regular.json'=>['Каталог квестов','quests'],
            'guide/reference-pve.json'=>['Общие игровые справочники','references'],'guide/reference-regular.json'=>['Общие игровые справочники','references'],
            'guide/story.json'=>['Сюжетные цепочки','quests'],'guide/wiki-quests.json'=>['Дополнительные квесты вики','quests'],
            'guide/quest-choices.json'=>['Варианты прохождения квестов','quests'],'guide/quest-conditions.json'=>['Условия квестов','quests'],
            'guide/quest-content.json'=>['Прохождения квестов','quests'],'guide/quest-map-index.json'=>['Связи квестов с локациями','quests'],
            'guide/quest-media.json'=>['Материалы квестов','quests'],'guide/quest-reward-support.json'=>['Поддержка наград квестов','quests'],
            'guide/ticket-map.json'=>['Карта сюжетных переходов','quests'],'guide/objective-patches.json'=>['Исправления целей','service'],
            'guide/quest-patch-1150.json'=>['Исправления квестов версии 1.15','service'],'guide/catalog-update.json'=>['История обновления каталога','service'],
            'guide/media-audit.json'=>['Проверка материалов','service'],'guide/media-localization.json'=>['Локализация материалов','service'],
            'guide/provenance.json'=>['Источники игровых справочников','service'],'source.json'=>['Источник данных документов','service'],
            'raid/maps.json'=>['Каталог интерактивных карт','maps'],'raid/item-names.json'=>['Названия предметов на картах','items'],
            'raid/loose-items.json'=>['Предметы вне контейнеров','items'],'raid/quest-index.json'=>['Квесты на интерактивных картах','quests'],
            'raid/correction-provenance.json'=>['Источники исправлений карт','service'],'raid/document-calibration.json'=>['Привязка карт документов','service'],
            'raid/navigator-provenance.json'=>['Источники навигации по картам','service'],'raid/source.json'=>['Источники интерактивных карт','service'],
        ];
        [$title,$group]=$titles[$path]??['Дополнительный справочник: '.str_replace(['.json','-','_'],['',' ',' '],basename($path)),'service'];
        return compact('title','group','mode');
    }
    public function rows(): \Illuminate\Support\Collection
    {
        $rows=collect();
        foreach(ContentDataset::orderBy('path')->get() as $dataset){
            $meta=self::metadata($dataset->path);$rows->push((object)[...$meta,'id'=>$dataset->id,'section'=>'','updated_at'=>$dataset->updated_at]);
            if(preg_match('~^guide/reference-(pve|regular)\.json$~',$dataset->path)){
                $keys=Cache::remember('admin-reference-sections-v1-'.$dataset->revision(),3600,fn()=>array_keys($dataset->content()));
                foreach(['achievements'=>['Достижения','achievements'],'crafts'=>['Рецепты крафтов','crafts'],'hideout'=>['Модули убежища','hideout'],
                    'barters'=>['Бартеры торговцев','trading'],'traders'=>['Торговцы','trading'],'maps'=>['Игровые локации','maps']] as $key=>[$title,$group]){
                    if(in_array($key,$keys,true)){$rows->push((object)['id'=>$dataset->id,'title'=>$title,'group'=>$group,'mode'=>$meta['mode'],'section'=>'/'.$key,'updated_at'=>$dataset->updated_at]);}
                }
            }
        }
        return $rows->sortBy(fn($row)=>$row->title.' '.$row->mode)->values();
    }
}
