<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Services\WikiSchema;
use Illuminate\Support\Facades\DB;
use Orchid\Screen\Screen;
use Orchid\Screen\Actions\Link;
use Orchid\Support\Facades\Layout;

/** Навигация по предметным каталогам и таблицам связей. */
final class WikiHomeScreen extends Screen
{
    public function name(): string { return 'Сущности и связи'; }
    public function description(): string { return 'Выберите каталог. Все связанные записи выбираются по названиям, идентификаторы создаются автоматически.'; }
    public function permission(): array { return ['wiki.content']; }
    public function query(): array { return []; }
    public function commandBar(): array { return []; }
    public function layout(): array
    {
        $groups=[
            'Каталоги'=>['items','keys','traders','locations','documents','skills','stations','achievements'],
            'Карты и точки'=>['maps','map_layers','map_points','point_categories','point_category_links','point_positions','point_world_positions','point_documents','item_spawns','key_usages','extractions'],
            'Квесты и условия'=>['quests','objectives','objective_types','objective_groups','objective_items','objective_locations','objective_points','objective_keys','prerequisite_groups','quest_prerequisites','quest_trader_requirements','quest_skill_requirements','quest_station_requirements'],
            'Награды и производство'=>['reward_sets','reward_items','reward_standing','reward_skills','reward_unlocks','crafts','craft_ingredients','offers'],
            'Версии, медиа и переводы'=>['versions','media','media_links','item_translations','point_translations','quest_translations'],
        ];$layouts=[];
        foreach($groups as $title=>$types){$links=[];foreach($types as $type){$count=DB::table('wiki_'.$type)->where('archived',0)->count();$links[]=Link::make(WikiSchema::TYPES[$type].' · '.$count)->route('platform.wiki.list',$type);}$layouts[]=Layout::rows($links)->title($title);}
        return $layouts;
    }
}
