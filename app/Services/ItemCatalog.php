<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\ContentDataset;
use Illuminate\Support\Facades\Cache;

/** Категории интерфейса из типов предметов; не переписывает старые ID и связи ключей. */
final class ItemCatalog
{
    public const GROUPS=['all'=>'Все предметы','keys'=>'Ключи и ключ-карты','tools'=>'Инструменты','containers'=>'Кейсы и контейнеры',
        'weapons'=>'Оружие','ammo'=>'Боеприпасы','mods'=>'Модификации','gear'=>'Экипировка','medicine'=>'Медицина','food'=>'Провизия','barter'=>'Бартер','other'=>'Прочее'];
    public static function classify(array $item): string
    {
        $types=$item['types']??[];$property=$item['properties']['propertiesType']??'';$name=mb_strtolower($item['name']??'');
        if(in_array('container',$types,true)||$property==='ItemPropertiesContainer'){return 'containers';}
        if(preg_match('/гаечн|разводно[йг]|газовый резак|болторез|отв[её]ртк|плоскогуб|кусачки/u',$name)){return 'tools';}
        if(in_array('keys',$types,true)||$property==='ItemPropertiesKey'||preg_match('/\bключ(?:[а-яё]*)\b|keycard|\bkey\b/ui',$name)){return 'keys';}
        foreach(['weapons'=>['gun','grenade','preset'],'ammo'=>['ammo','ammoBox'],'mods'=>['mods'],
            'gear'=>['armor','rig','backpack','glasses','helmet','headphones','armorPlate','wearable'],
            'medicine'=>['meds','injectors'],'food'=>['provisions'],'barter'=>['barter']] as $group=>$matches){if(array_intersect($matches,$types)){return $group;}}
        $category=$item['category']??'';if(is_array($category)){$category=$category['normalizedName']??'';}
        return array_key_exists($category,self::GROUPS)&&$category!=='all'?$category:'other';
    }
    public function groups(): array
    {
        $datasets=ContentDataset::whereIn('path',['guide/items-pve.json','guide/items-regular.json'])->get();
        $key='admin-item-groups-v1-'.hash('sha256',serialize([$datasets->first()?->getConnection()->getDatabaseName(),$datasets->pluck('payload','path')->all()]));
        return Cache::remember($key,3600,function() use($datasets): array {
            $map=[];foreach($datasets as $dataset){foreach($dataset->content()['items']??[] as $item){if(isset($item['id'])){$map[$item['id']]=self::classify($item);}}}
            $groups=array_fill_keys(array_keys(self::GROUPS),[]);foreach($map as $id=>$group){$groups[$group][]=$id;}return $groups;
        });
    }
    public function filter($query,string $type,string $group): void
    {
        if($group==='all'){return;}$groups=$this->groups();
        if($group==='related'){$ids=$groups['keys'];$operator='whereNotIn';}
        elseif($group==='other'){$ids=array_merge(...array_values(array_diff_key($groups,['all'=>true,'other'=>true])));$operator='whereNotIn';}
        else{$ids=$groups[$group]??[];$operator='whereIn';}
        if($type==='keys'){$query->whereIn('item_id',fn($q)=>$q->select('id')->from('wiki_items')->{$operator}('external_id',$ids));}
        else{$query->{$operator}('external_id',$ids);}
    }
}
