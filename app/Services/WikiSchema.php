<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\Wiki\Record;
use Illuminate\Support\Facades\{DB, Schema};

/** Белый список предметных таблиц и метаданные обычных форм Orchid. */
final class WikiSchema
{
    public const TYPES = [
        'versions'=>'Версии данных','traders'=>'Торговцы','locations'=>'Локации','maps'=>'Изображения карт','map_layers'=>'Этажи карт',
        'documents'=>'Документы','items'=>'Предметы','keys'=>'Ключи','point_categories'=>'Категории точек','map_points'=>'Точки карты',
        'point_category_links'=>'Категории точки','point_positions'=>'Позиции на изображении','point_world_positions'=>'Мировые координаты',
        'point_documents'=>'Документы в точках','item_spawns'=>'Спавны предметов','key_usages'=>'Использование ключей','extractions'=>'Выходы',
        'quests'=>'Квесты и связи','objective_types'=>'Действия целей','objectives'=>'Цели квестов','objective_groups'=>'Группы условий целей',
        'objective_items'=>'Предметы для целей','objective_points'=>'Точки выполнения целей','objective_locations'=>'Локации целей','objective_keys'=>'Ключи для целей',
        'prerequisite_groups'=>'Группы условий открытия','quest_prerequisites'=>'Предшествующие квесты','quest_trader_requirements'=>'Требования торговцев',
        'skills'=>'Навыки','stations'=>'Модули убежища','achievements'=>'Достижения','crafts'=>'Крафты','craft_ingredients'=>'Ингредиенты крафтов',
        'offers'=>'Предложения торговцев','reward_sets'=>'Наборы наград','reward_items'=>'Предметы наград','reward_standing'=>'Репутация за квест',
        'reward_skills'=>'Навыки за квест','reward_unlocks'=>'Открываемые возможности','quest_skill_requirements'=>'Требования навыков',
        'quest_station_requirements'=>'Требования убежища','media'=>'Изображения и медиа','media_links'=>'Привязки медиа',
        'item_translations'=>'Переводы предметов','point_translations'=>'Переводы точек','quest_translations'=>'Переводы квестов',
    ];
    private array $columns = [];
    private array $foreigns = [];

    public function table(string $type): string { abort_unless(isset(self::TYPES[$type]),404);return 'wiki_'.$type; }
    public function identity(string $type): string
    { return DB::getDriverName()==='sqlite'||!isset($this->columns($type)['id'])?'rowid':'id'; }
    public function model(string $type): Record { return (new Record)->setTable($this->table($type))->setKeyName($this->identity($type)); }
    public function query(string $type) { return $this->model($type)->newQuery()->selectRaw($this->identity($type).' AS rowid, '.$this->table($type).'.*'); }
    public function record(string $type,int|string $id) { return $this->query($type)->where($this->identity($type),$id); }
    public function columns(string $type): array
    {
        if(DB::getDriverName()==='sqlite'){return $this->columns[$type]??=collect(DB::select('PRAGMA table_info('.$this->table($type).')'))->keyBy('name')->all();}
        return $this->columns[$type]??=collect(Schema::getColumns($this->table($type)))->mapWithKeys(fn($column)=>[$column['name']=>(object)[
            'name'=>$column['name'],'type'=>$column['type'],'notnull'=>!$column['nullable'],'dflt_value'=>$column['default'],
        ]])->all();
    }
    public function foreigns(string $type): array
    {
        if (isset($this->foreigns[$type])) { return $this->foreigns[$type]; }
        $fields = [];
        $foreigns=DB::getDriverName()==='sqlite'?DB::select('PRAGMA foreign_key_list('.$this->table($type).')'):array_merge(...array_map(
            fn($foreign)=>array_map(fn($column,$target)=>(object)['table'=>$foreign['foreign_table'],'from'=>$column,'to'=>$target],$foreign['columns'],$foreign['foreign_columns']),
            Schema::getForeignKeys($this->table($type))));
        foreach ($foreigns as $foreign) {
            $target = substr($foreign->table,5);
            if (isset(self::TYPES[$target]) && ($foreign->to === 'id' || $foreign->to === 'code' || $foreign->to === 'item_id')) {
                $fields[$foreign->from] = ['type'=>$target,'column'=>$foreign->to];
            }
        }
        if (isset($this->columns($type)['location_id'])) { $fields['location_id'] = ['type'=>'locations','column'=>'id']; }
        return $this->foreigns[$type] = $fields;
    }
    public function fields(string $type): array
    {
        return array_filter($this->columns($type), fn ($column) => !in_array($column->name,
            ['rowid','id','external_id','slug','code','legacy_quest_id','display_name','search_text','revision','source_kind','source_hash','source_path'],true)
            && !($type==='point_positions'&&$column->name==='location_id'));
    }
    public function children(string $type): array
    {
        $children = [];
        foreach (self::TYPES as $child=>$title) {
            foreach ($this->foreigns($child) as $field=>$foreign) {
                if ($foreign['type'] === $type) { $children[] = compact('child','title','field','foreign'); }
            }
        }
        return $children;
    }
    public function label(string $type, array $values): string
    {
        if($type==='versions'){return ($values['patch']??'Версия').' · '.($values['valid_from']??'');}
        if($type==='media'){return mb_substr(trim($values['caption']??'')?:($values['url']??'Медиа'),0,500);}
        if($type==='keys'){return (string)DB::table('wiki_items')->where('id',$values['item_id'])->value('name');}
        foreach (['name','title','description'] as $field) {
            if (is_string($values[$field] ?? null) && trim($values[$field]) !== '') {
                $label = mb_substr($values[$field],0,160);
                if ($type === 'quests') { $label .= ' · '.strtoupper($values['mode']); }
                if ($type === 'map_points') {
                    $label .= ' · '.DB::table('wiki_locations')->where('id',$values['location_id'])->value('name').' · '.(['pve'=>'PVE','regular'=>'PVP','all'=>'Общая'][$values['mode']??'all']);
                    if(isset($values['id'])){$position=DB::table('wiki_point_world_positions')->where('point_id',$values['id'])->where('archived',0)->orderBy('order_index')->first();
                        if($position){$label.=' · X '.round($position->world_x,1).' / Z '.round($position->world_z,1);}else{$position=DB::table('wiki_point_positions')->where('point_id',$values['id'])->where('archived',0)->orderBy('order_index')->first();if($position){$label.=' · '.round($position->x_percent,2).'%, '.round($position->y_percent,2).'%';}}}
                }
                if ($type === 'objectives' && isset($values['quest_id'])) {
                    $parent = $this->query('quests')->where('id',$values['quest_id'])->value('display_name');
                    $label = $parent.' — '.$label;
                }
                return $label;
            }
        }
        $labels = [];
        foreach ($this->foreigns($type) as $field=>$foreign) {
            if (($values[$field] ?? null) === null) { continue; }
            $label = $this->query($foreign['type'])->where($foreign['column'],$values[$field])->value('display_name');
            if ($label) { $labels[] = $label; }
        }
        $label=implode(' / ',array_unique($labels)) ?: self::TYPES[$type];
        foreach(['operator','purpose','timing'] as $field){if(isset($values[$field])){$label.=' · '.($this->choices($type,$field)[$values[$field]]??$values[$field]);}}
        if(isset($values['order_index'])){$label.=' · '.($values['order_index']+1);}
        return mb_substr($label,0,500);
    }
    public static function search(string $text): string
    { return mb_strtolower(preg_replace('/[\s_-]+/u',' ',trim($text))); }
    public function title(string $field): string
    {
        return ['name'=>'Название','title'=>'Название','description'=>'Описание','location_id'=>'Локация','map_id'=>'Изображение карты','layer_id'=>'Этаж',
            'item_id'=>'Предмет','key_item_id'=>'Ключ','trader_id'=>'Торговец','quest_id'=>'Квест','objective_id'=>'Цель квеста',
            'point_id'=>'Точка карты','document_id'=>'Документ','version_id'=>'Версия данных','group_id'=>'Группа условий','parent_id'=>'Родительская группа',
            'prerequisite_quest_id'=>'Предшествующий квест','reward_set_id'=>'Набор наград','skill_id'=>'Навык','station_id'=>'Модуль убежища',
            'craft_id'=>'Крафт','offer_id'=>'Предложение торговца','achievement_id'=>'Достижение','media_id'=>'Изображение / медиа',
            'type'=>'Тип','category'=>'Категория','operator'=>'Условия группы','role'=>'Назначение предмета','timing'=>'Когда выдать',
            'amount'=>'Количество','count'=>'Количество','found_in_raid'=>'Найдено в рейде','optional'=>'Необязательная связь',
            'order_index'=>'Порядок','x_percent'=>'Координата X (%)','y_percent'=>'Координата Y (%)','world_x'=>'Мировая X','world_y'=>'Мировая Y','world_z'=>'Мировая Z',
            'image_url'=>'Адрес изображения','icon_url'=>'Адрес иконки','wiki_url'=>'Ссылка на вики','width'=>'Ширина изображения','height'=>'Высота изображения',
            'weight'=>'Масса','grid_width'=>'Ширина в ячейках','grid_height'=>'Высота в ячейках','max_uses'=>'Число использований ключа',
            'spawn_chance'=>'Вероятность (0–1)','notes'=>'Примечание','usage_type'=>'Способ доступа','requirements'=>'Требования','cost_rub'=>'Стоимость в рублях',
            'level_req'=>'Уровень игрока','is_story'=>'Сюжетный квест','kappa_required'=>'Нужен для Каппы','published'=>'Опубликован','mode'=>'Режим',
            'status'=>'Статус предшествующего квеста','hard_requirement'=>'Обязательное условие','loyalty_level'=>'Уровень лояльности','standing'=>'Репутация',
            'level'=>'Уровень','duration_seconds'=>'Длительность (сек.)','consumed'=>'Расходуется','experience'=>'Опыт','url'=>'Адрес файла',
            'caption'=>'Подпись','source_url'=>'Источник','author'=>'Автор','locale'=>'Язык','patch'=>'Патч / версия игры','valid_from'=>'Действует с',
            'active'=>'Активна','archived'=>'В архиве','purpose'=>'Раздел условий',
        ][$field] ?? app(ContentFields::class)->label($field);
    }
    public function choices(string $type,string $field): ?array
    {
        return match ($field) {
            'operator'=>['and'=>'Все условия (И)','or'=>'Любое условие (ИЛИ)'],
            'mode'=>$type === 'map_points' ? ['all'=>'Общая точка','pve'=>'PVE','regular'=>'PVP'] : ['pve'=>'PVE','regular'=>'PVP'],
            'timing'=>['start'=>'При начале','finish'=>'При завершении'],
            'role'=>['obtain'=>'Найти','handover'=>'Передать','plant'=>'Разместить','mark'=>'Отметить','weapon'=>'Оружие','mod'=>'Модификация','wear'=>'Надеть','not_wear'=>'Не надевать'],
            'usage_type'=>['unlock'=>'Открывает','optional'=>'Необязательный ключ','bypass'=>'Есть обходной путь'],
            'status'=>['complete'=>'Завершён','active'=>'В процессе','failed'=>'Провален','available'=>'Доступен'],
            'locale'=>['ru'=>'Русский','en'=>'Английский','ja'=>'Японский'],
            'type'=>$type === 'extractions' ? ['pmc'=>'ЧВК','scav'=>'Дикий','shared'=>'Общий','coop'=>'Совместный','transit'=>'Переход','secret'=>'Секретный'] : null,
            'purpose'=>['items'=>'Предметы','item'=>'Один предмет','questItem'=>'Квестовый предмет','usingWeapon'=>'Оружие','usingWeaponMods'=>'Модификации оружия','wearing'=>'Надеть','notWearing'=>'Не надевать','requiredKeys'=>'Необходимые ключи'],
            default=>null,
        };
    }
}
