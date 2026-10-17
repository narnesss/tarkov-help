import {normalize} from './model.mjs?v=page-content-20261017';

export const UNKNOWN_LOOSE_ITEM='unknown';
export const LOOSE_FALLBACK_ICON='assets/raid-icons/loose-items.svg';
export const looseItemIds=point=>[...new Set((point.itemIds||[]).filter(id=>typeof id==='string'&&id.length))];
export function matchingLooseItems(point,selection=null){
  const ids=looseItemIds(point),available=ids.length?ids:[UNKNOWN_LOOSE_ITEM];
  return selection===null?available:available.filter(id=>selection.has(id));
}
export function looseItemRecord(id,catalog={}){
  const item=catalog[id];
  return item||{id,name:id===UNKNOWN_LOOSE_ITEM?'Предметы без уточнения':'Неуточнённый предмет',
    icon:/^[a-f0-9]{24}$/.test(id)?`https://assets.tarkov.dev/${id}-icon.webp`:LOOSE_FALLBACK_ICON};
}
export function looseItemOptions(points,catalog={}){
  const rows=new Map();
  for(const point of points){
    if(point.category!=='loose-loot')continue;
    for(const id of matchingLooseItems(point)){
      if(!rows.has(id))rows.set(id,{...looseItemRecord(id,catalog),count:0});
      rows.get(id).count++;
    }
  }
  return [...rows.values()].sort((a,b)=>a.name.localeCompare(b.name,'ru'));
}
export function filterLooseItems(items,query=''){
  const words=normalize(query).split(/\s+/).filter(Boolean);
  return items.filter(item=>words.every(word=>normalize(item.name+' '+(item.shortName||'')).includes(word)));
}
export function loosePresentation(point,catalog,selection=null,query=''){
  const ids=matchingLooseItems(point,selection),candidates=ids.map(id=>looseItemRecord(id,catalog));
  const item=(query.trim()?filterLooseItems(candidates,query)[0]:null)||candidates[0]||looseItemRecord(UNKNOWN_LOOSE_ITEM,catalog);
  const extra=Math.max(0,ids.length-1),last=extra%10,tail=extra%100;
  const word=tail>=11&&tail<=14?'вариантов':last===1?'вариант':last>=2&&last<=4?'варианта':'вариантов';
  return {item,ids,extra,title:extra?`${item.name} · ещё ${extra} ${word}`:item.name};
}

/** Per-profile, per-map and per-mode selection; null includes new source items. */
export class LooseLootPreferences{
  constructor(value={}){this.value=value&&typeof value==='object'&&!Array.isArray(value)?structuredClone(value):{};}
  scope(mode,map){const stored=this.value[mode]?.[map]||{};return {...stored,selected:Array.isArray(stored.selected)?stored.selected.filter(v=>typeof v==='string'):null,query:typeof stored.query==='string'?stored.query:'',expanded:stored.expanded===true};}
  selected(mode,map){const selection=this.scope(mode,map).selected;return selection===null?null:new Set(selection);}
  update(mode,map,patch){const current=this.scope(mode,map);this.value[mode]={...this.value[mode],[map]:{...current,...patch}};}
  toggle(mode,map,id,checked,available){
    const current=this.selected(mode,map)||new Set(available);
    checked?current.add(id):current.delete(id);
    this.update(mode,map,{selected:[...current]});
  }
  all(mode,map){this.update(mode,map,{selected:null});}
  none(mode,map){this.update(mode,map,{selected:[]});}
}
