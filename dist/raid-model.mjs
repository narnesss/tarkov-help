import {ruText} from './ru-data.mjs?v=page-content-20261017';
import { normalize } from './model.mjs?v=page-content-20261017';
import {matchingLooseItems,LOOSE_FALLBACK_ICON} from './loose-loot-model.mjs?v=page-content-20261017';

export const CATEGORIES = {
  'extract-pmc': ['Выходы ЧВК', '#a7d967', '↗'],
  'extract-scav': ['Выходы Диких', '#e7c879', '↗'],
  'extract-shared': ['Общие выходы', '#80dbc8', '↗'],
  transit: ['Переходы', '#ddacff', '⇄'],
  'spawn-pmc': ['Спавны ЧВК', '#75baff', '⊕'],
  'spawn-scav': ['Спавны Диких', '#ffc270', '⊕'],
  'spawn-sniper': ['Снайперы', '#ff897a', '⊕'],
  'spawn-other': ['Другие спавны', '#e6a0d3', '⊕'],
  'boss-zone': ['Боссы и особые отряды', '#ff777b', '♛'],
  loot: ['Контейнеры с лутом', '#e3cc83', '▣'],
  'loose-loot': ['Предметы вне контейнеров', '#80d6ae', '◆'],
  'locked-door': ['Запертые двери / ключи', '#a0a5ff', '⚿'],
  switch: ['Рубильники', '#edda6e', 'ϟ'],
  btr: ['Остановки БТР', '#72cec6', '▰'],
  'stationary-weapon': ['Стационарное оружие', '#fca985', '⊞'],
  hazard: ['Опасные зоны', '#fc7b66', '!'],
  quest: ['Квестовые места', '#c593ed', '◇'],
};
export const LOOT_NAMES = {
  'duffle-bag':'Спортивная сумка','drawer':'Картотека','weapon-box':'Оружейный ящик',
  'toolbox':'Ящик с инструментами','wooden-crate':'Деревянный ящик','jacket':'Куртка',
  'pc-block':'Компьютер','cash-register':'Касса','buried-barrel-cache':'Закопанная бочка',
  'grenade-box':'Ящик с гранатами','dead-scav':'Мёртвый Дикий','medbag-smu06':'Медицинская сумка',
  'ground-cache':'Тайник в земле','pmc-body':'Тело ЧВК','wooden-ammo-box':'Ящик с патронами',
  'technical-supply-crate':'Ящик технического снабжения','medcase':'Медицинский ящик',
  'plastic-suitcase':'Пластиковый чемодан','safe':'Сейф','ration-supply-crate':'Ящик провизии',
  'medical-supply-crate':'Ящик медицинского снабжения','civilian-body':'Тело гражданского',
  'bank-safe':'Банковский сейф','bank-cash-register':'Банковская касса','scav-body':'Тело Дикого',
  'lab-technician-body':'Тело лаборанта','shturmans-stash':'Тайник Штурмана',
};
const SIMPLE_NAMES = {'PMC spawn':'Спавн ЧВК','Scav spawn':'Спавн Дикого','Sniper Scav spawn':'Спавн снайпера','Locked access':'Запертая дверь','Sniper':'Снайперская зона','Mine':'Мины','Landmine':'Мины','Claymore':'Мины направленного действия'};
export const titleOf = p => LOOT_NAMES[p.lootType] || SIMPLE_NAMES[p.name] || ruText(p.name);
export const validPosition = p => p && ['x','y','z'].every(k => Number.isFinite(p[k]));

// The Wiki's pictograms, distributed by tarkov.dev; all files are served locally.
const CATEGORY_ICONS = {
  'extract-pmc':'extract_pmc','extract-scav':'extract_scav','extract-shared':'extract_shared',
  transit:'extract_transit','spawn-pmc':'spawn_pmc','spawn-scav':'spawn_scav',
  'spawn-sniper':'spawn_sniper_scav','spawn-other':'spawn_rogue','boss-zone':'spawn_boss',
  loot:'container_crate','loose-loot':'loose_loot','locked-door':'lock',switch:'switch',
  btr:'btr_stop','stationary-weapon':'stationarygun',hazard:'hazard',quest:'quest_objective',
};
const LOOT_ICON_ALIASES = {
  'medbag-smu06':'medbag','bank-safe':'safe','bank-cash-register':'cash-register',
  'pmc-body':'dead-scav','civilian-body':'dead-scav','scav-body':'dead-scav',
  'lab-technician-body':'dead-scav','shturmans-stash':'weapon-box',
  'medical-supply-crate':'crate','technical-supply-crate':'crate','ration-supply-crate':'crate',
};
export function iconPath(p) {
  if(p.category==='loose-loot')return LOOSE_FALLBACK_ICON;
  let name = CATEGORY_ICONS[p.category]||'quest_objective';
  if (p.category === 'loot' && p.lootType in LOOT_NAMES) name = 'container_' + (LOOT_ICON_ALIASES[p.lootType] || p.lootType);
  return `assets/raid-icons/${name}.png`;
}

export function rasterRegion(map, position, floor = floorOf(map,position)) {
  return [...(map.documentMap?.regions || [])].reverse().find(r =>
    (!r.floorIds?.length || r.floorIds.includes(floor)) &&
    (!r.bounds || within(position,r.bounds)) &&
    (!r.height || (position.y >= r.height[0] && position.y < r.height[1]))
  ) || map.documentMap;
}
export function projectRaster(map, position, floor = floorOf(map,position), region = rasterRegion(map,position,floor)) {
  const [a,b,c,d,e,f] = region.matrix;
  return {x:a*position.x+b*position.z+c,y:d*position.x+e*position.z+f};
}

function within(p, bounds) {
  const [a,b] = bounds;
  return p.x >= Math.min(a[0],b[0]) && p.x <= Math.max(a[0],b[0]) && p.z >= Math.min(a[1],b[1]) && p.z <= Math.max(a[1],b[1]);
}
export function floorOf(map, position) {
  // Navigator resolves overlapping floor volumes in their authored order.
  // Do not substitute the old smallest-volume heuristic for these definitions.
  if(map.floorPolicy==='navigator-first-match'){
    for(const floor of map.floors){
      if(floor.id===map.baseFloor.id)continue;
      if((floor.extents||[]).some(e=>position.y>=e.height[0]&&position.y<e.height[1]&&(!e.bounds?.length||e.bounds.some(b=>within(position,b)))))return floor.id;
    }
    return map.baseFloor.id;
  }
  let winner = map.baseFloor.id, best = -Infinity;
  for (const floor of map.floors) {
    for (const e of floor.extents || []) {
      const [low, high] = e.height;
      if (position.y < low || position.y >= high || (e.bounds?.length && !e.bounds.some(b => within(position,b)))) continue;
      const score = (e.bounds?.length ? 10000 : 0) - (high - low);
      if (score > best) { best = score; winner = floor.id; }
    }
  }
  return winner;
}
export function poiFloor(map, p) {
  if(map.documentMap&&p.documentFloorOverride&&[map.baseFloor.id,...map.floors.map(f=>f.id)].includes(p.documentFloorOverride))return p.documentFloorOverride;
  if(p.navigator&&!map.documentMap)return floorOf(map,p.position);
  if(p.floorOverride&&[map.baseFloor.id,...map.floors.map(f=>f.id)].includes(p.floorOverride))return p.floorOverride;
  // The source extract volume straddles the floor boundary, but Gate 3 is the ground exit.
  if (map.id === 'factory' && p.kind === 'extract' && [p.name,p.sourceName,...(p.aliases||[])].some(s=>s==='Gate 3'||s==='Ворота 3'||s==='EXFIL_Gate3')) return map.baseFloor.id;
  return floorOf(map,p.position);
}

// A source point and a hand-corrected document inset are separate coordinate spaces.
export function displayPosition(map,p){return map.documentMap?(p.documentDisplayPosition||p.displayPosition||p.position):p.position;}
export function filterPois(pois, { categories, lootTypes, looseItems = null, looseCatalog, query = '', floor = 'all', checked = new Set(), status = 'all' }) {
  const words = normalize(query).split(/\s+/).filter(Boolean);
  return pois.filter(p => {
    if (!categories.has(p.category) || (p.category === 'loot' && !lootTypes.has(p.lootType))) return false;
    if(p.category==='loose-loot'&&!matchingLooseItems(p,looseItems).length)return false;
    if (floor !== 'all' && p.floor !== floor) return false;
    if (status === 'remaining' && checked.has(p.id)) return false;
    if (status === 'checked' && !checked.has(p.id)) return false;
    const selectedNames=p.category==='loose-loot'&&looseCatalog?matchingLooseItems(p,looseItems).flatMap(id=>[looseCatalog[id]?.name,looseCatalog[id]?.shortName].filter(Boolean)):null;
    const text = normalize((selectedNames?.length?[CATEGORIES[p.category]?.[0],...selectedNames]:[titleOf(p), p.name, p.description, p.trader, CATEGORIES[p.category]?.[0], ...(p.aliases || []), ...(p.itemNames || [])]).join(' '));
    return words.every(w => text.includes(w));
  });
}

// Same x/z rotation and scale as the authored map's tarkov.dev calibration.
export function projectPosition(map, position) {
  const r = (map.coordinateRotation || 0) * Math.PI / 180;
  const x = position.x * Math.cos(r) - position.z * Math.sin(r);
  const z = position.x * Math.sin(r) + position.z * Math.cos(r);
  const [sx,mx,sz,mz] = map.transform;
  return { x: x * sx + mx, y: -z * sz + mz };
}

export function looseFromApi(mapId, mapsResponse, itemsResponse, localeResponse) {
  const rawMaps = mapsResponse?.data?.maps;
  const items = itemsResponse?.data?.items;
  if (!rawMaps || typeof rawMaps !== 'object' || !items || typeof items !== 'object') throw new Error('Неподдерживаемый формат данных API.');
  const map = Object.values(rawMaps).find(m => m.normalizedName === mapId);
  if (!map || !Array.isArray(map.lootLoose)) throw new Error('API не вернул россыпь для этой локации.');
  const locale = localeResponse?.data || {};
  return map.lootLoose.filter(p => validPosition(p.position)).map((p,i) => {
    const ids = (p.items || []).map(item => typeof item === 'string' ? item : item.id).filter(id => typeof id === 'string');
    const itemNames = ids.map(id => locale[items[id]?.name] || items[id]?.name || id);
    return { id:`live-loose-${i}`, kind:'loose-loot', category:'loose-loot', name:itemNames.join(' · ') || 'Возможное место лута', itemNames, itemIds:ids, position:p.position };
  });
}
