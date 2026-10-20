import {siteHref,pageParams,canonicalURL,usesPageRoutes} from './site-routes.mjs?v=page-content-20261017';
import {publicLinkURL,publicAttribution} from './public-links.mjs?v=page-content-20261017';
import {LooseLootFilter} from './loose-loot-ui.mjs?v=documents-access-20261020';
import {LooseLootPreferences,loosePresentation,looseItemRecord,looseItemIds,LOOSE_FALLBACK_ICON} from './loose-loot-model.mjs?v=page-content-20261017';
import {resolveGameMode} from './game-mode.mjs?v=page-content-20261017';
import {readPersonal,writePersonal} from './profile-store.mjs?v=loading-20261018';
const storePersonal=(key,value)=>{if(!writePersonal(key,value))throw new Error('Локальное сохранение недоступно');};
import {ruText,localizeDisplay} from './ru-data.mjs?v=page-content-20261017';
import {questExtras,mediaFor} from './quest-extras.mjs?v=documents-access-20261020';
import {pageDataset} from './page-data.mjs?v=documents-access-20261020';
import {questGalleryPlan} from './quest-view-model.mjs?v=page-content-20261017';
import {galleryBlock} from './gallery.mjs?v=documents-access-20261020';
import {json} from './guide-common.mjs?v=documents-access-20261020';
import {loadContentBundle} from './content-bundles.mjs?v=documents-access-20261020';
import {bindMapTooltip} from './raid-tooltip.mjs?v=page-content-20261017';
import {scopedRaidPoints} from './raid-scope.mjs?v=page-content-20261017';
import {QuestMapFocusReceiver} from './quest-map-messages.mjs?v=page-content-20261017';
import { CATEGORIES, LOOT_NAMES, titleOf, validPosition, floorOf, poiFloor, filterPois, projectPosition, projectRaster, rasterRegion, iconPath, displayPosition } from './raid-model.mjs?v=page-content-20261017';
import {rasterTileLayer} from './raid-map-geometry.mjs';

const $ = id => document.getElementById(id);
const el = (tag, text = '', cls = '') => { const n = document.createElement(tag); n.textContent = ruText(text); n.className = cls; return n; };
const read = readPersonal;
const progressKey = 'kord-atlas-raid-progress-v1';
const prefsKey = 'kord-atlas-raid-view-v1';
const original = read(progressKey) || {};
let saved = { checked: Array.isArray(original.checked) ? original.checked.filter(x => typeof x === 'string') : [], notes: original.notes && typeof original.notes === 'object' && !Array.isArray(original.notes) ? original.notes : {} };
const embedded=pageParams().get('embed')==='quest';if(embedded)document.body.classList.add('raid-embed');
const compactRaid=()=>!embedded&&innerWidth<=1200;
const prefs = embedded?{}:read(prefsKey)||{};
const loosePrefs=new LooseLootPreferences(prefs.looseLoot);
let itemCatalog={},looseFilter;
let activeLooseSelection=null;const looseViews=new Map();
const categories = new Set((Array.isArray(prefs.categories) ? prefs.categories : Object.keys(CATEGORIES)).filter(k => CATEGORIES[k]));
const lootTypes = new Set(Array.isArray(prefs.lootTypes) ? prefs.lootTypes : Object.keys(LOOT_NAMES));
let maps = [], definition, pois = [], visible = [], selected = null, leaflet, markerLayer, outlineLayer, labelsLayer, artwork;
let currentFloor = 'all', listLimit = 100, requestId = 0, artworkId = 0, markers = new Map(), timer;
let itemNames = {}, questIndex = {}, btrReference, routeLayer;
const pointMedia=new Map();
let iconSize = Math.max(16,Math.min(36,Number(prefs.iconSize) || 24));
const dataCache = new Map(), svgCache = new Map();
const params = pageParams();
let gameMode=resolveGameMode(params.get('mode'),read('kord-atlas-game-mode'));
const requestedArtwork=params.get('artwork');
let artworkMode=['source','documents'].includes(requestedArtwork)?requestedArtwork:prefs.artworkPreferenceVersion===3&&['source','documents'].includes(prefs.artworkMode)?prefs.artworkMode:'source';
let questFilter=params.get('quest')||'',objectiveFilter=embedded?'':params.get('objective')||'';
const focusReceiver=new QuestMapFocusReceiver(()=>({questId:questFilter,mode:gameMode,mapId:definition?.id||params.get('map'),viewId:params.get('view')||''}),message=>{focusEmbeddedObjective(message.objectiveId).catch(error=>notify(error.message));},{enabled:embedded});
let focusedQuestPoints=[],focusSequence=0;
const routeId=params.get('route')||'';
const floorName = id => id === 'all' ? 'Все этажи' : (definition?.floors.find(f => f.id === id)?.name || definition?.baseFloor.name || 'Основной уровень');
const keyOf = p => `${definition.id}:${p.id}`;
const checked = p => saved.checked.includes(keyOf(p));
const link = (text, href) => { const target=publicLinkURL(href);if(!target)return document.createDocumentFragment();const a = el('a', text); a.href = target; a.target = '_blank'; a.rel = 'noopener noreferrer'; return a; };
function notify(text) { clearTimeout(timer); $('raid-toast').textContent = text; $('raid-toast').hidden = false; timer = setTimeout(() => { $('raid-toast').hidden = true; },4500); }
function persist() { try { storePersonal(progressKey, saved); } catch { notify('Браузер не разрешил сохранение отметок.'); } }
function savePrefs() { if(embedded)return;try { storePersonal(prefsKey, {...(read(prefsKey)||{}),looseLoot:loosePrefs.value,map:definition.id,categories:[...categories],lootTypes:[...lootTypes],iconSize,artworkMode,artworkPreferenceVersion:3,floors:{...(read(prefsKey)?.floors||{}),[definition.id+':'+artworkMode]:currentFloor},query:$('raid-search').value,status:$('raid-status').value,labels:$('raid-labels').checked,legacy:$('raid-legacy').checked,tab:$('raid-list-tab').getAttribute('aria-selected')==='true'?'list':'filters'}); } catch { /* Map remains usable without storage. */ } }
function setUrl() {
  const u = new URL(usesPageRoutes()?'raid.html':location.href,location.origin+'/'); u.search = ''; u.searchParams.set('map',definition.id); u.searchParams.set('floor',currentFloor);
  if (selected) u.searchParams.set('poi',selected.id);
  u.searchParams.set('mode',gameMode);u.searchParams.set('artwork',artworkMode);if(embedded)u.searchParams.set('embed','quest');
  if(embedded&&params.has('view'))u.searchParams.set('view',params.get('view'));
  if(questFilter)u.searchParams.set('quest',questFilter);if(objectiveFilter)u.searchParams.set('objective',objectiveFilter);if(routeId)u.searchParams.set('route',routeId);
  history.replaceState(null,'',canonicalURL(u));
  const aliases = {'streets-of-tarkov':'streets','ground-zero':'groundzero','the-lab':'lab','the-labyrinth':'labyrinth'};
  $('documents-link').href = siteHref(`documents.html?map=${aliases[definition.id] || definition.id}`);
}
function updateSidebarAccess() {
  const open=$('raid-layout').classList.contains('filters-open'),compact=compactRaid();
  document.querySelector('.raid-sidebar').inert=embedded||compact&&!open;
  document.querySelector('.raid-main').inert=compact&&open;
  $('raid-backdrop').hidden=!compact||!open;
}
function setSidebar(open) {
  const restore=!open&&document.querySelector('.raid-sidebar').contains(document.activeElement);
  $('raid-layout').classList.toggle('filters-open',open);$('raid-menu').setAttribute('aria-expanded',String(open));
  $('raid-menu').setAttribute('aria-label',open?'Закрыть фильтры':'Открыть фильтры');updateSidebarAccess();
  if(restore)$('raid-menu').focus({preventScroll:true});
}
function tab(name) {
  for (const key of ['filters','list']) { const active = key === name; $('raid-'+key+'-tab').setAttribute('aria-selected',String(active)); $('raid-'+key+'-tab').tabIndex = active ? 0 : -1; }
  $('raid-filters').hidden = name !== 'filters'; $('raid-results').hidden = name !== 'list';if(definition)savePrefs();
}
function sourcePixelMap(){if(!definition?.sourceCalibrations||definition.documentMap)return null;return definition.sourceCalibrations[currentFloor]||definition.sourceCalibrations[definition.baseFloor.id];}
function pointLatLng(p){
 const position=displayPosition(definition,p);
 if(definition.documentMap){const v=p.documentPixel?{x:p.documentPixel[0],y:p.documentPixel[1]}:projectRaster(definition,position,p.floor);return L.latLng(-v.y,v.x);}
 const source=sourcePixelMap();if(source){const [a,b,c,d,e,f]=source.matrix;return L.latLng(-(d*position.x+e*position.z+f),a*position.x+b*position.z+c);}
 return L.latLng(position.z,position.x);
}
function artworkBounds(){const d=definition.documentMap||sourcePixelMap();return d?L.latLngBounds([-d.height,0],[0,d.width]):bounds(definition.bounds);}
function focusPoint(p) {
 const position=pointLatLng(p);leaflet.setView(position,Math.max(leaflet.getZoom(),(definition.documentMap||sourcePixelMap())?1:5),{animate:false});
 // На планшете центрируем метку в видимой части карты над карточкой.
 if(compactRaid()&&!$('raid-detail').hidden){
  const map=$('raid-map').getBoundingClientRect(),panel=$('raid-detail').getBoundingClientRect();
  const height=Math.max(60,Math.min(map.height,panel.top-map.top)),target=L.point(map.width/2,height/2);
  leaflet.panBy(leaflet.latLngToContainerPoint(position).subtract(target),{animate:false});
 }
}
function bounds(raw) { return L.latLngBounds([raw[0][1],raw[0][0]],[raw[1][1],raw[1][0]]); }
function createCrs(map) {
  return L.extend({}, L.CRS.Simple, {
    transformation: new L.Transformation(1,0,1,0),
    projection: {
      project(ll) { const p = projectPosition(map,{x:ll.lng,z:ll.lat}); return L.point(p.x,p.y); },
      unproject(p) {
        const [sx,mx,sz,mz] = map.transform;
        const x = (p.x-mx)/sx, z = -(p.y-mz)/sz, a = -(map.coordinateRotation || 0)*Math.PI/180;
        return L.latLng(x*Math.sin(a)+z*Math.cos(a),x*Math.cos(a)-z*Math.sin(a));
      },
      bounds: L.bounds([-Infinity,-Infinity],[Infinity,Infinity]),
    },
  });
}
function fit() {
  if(!leaflet)return;
  const box=artworkBounds();
  // Some source objects are outside the older drawing (new areas / train cargo).
  // Keep them reachable and visible; never clamp their coordinates onto a false location.
  if(definition.documentMap||!definition.coordinateSource)for(const p of pois.filter(p=>!p.hiddenByDefault))box.extend(pointLatLng(p));
  leaflet.fitBounds(box,{padding:[25,35],animate:false});
}
function fitQuestPoints(){
 if(!leaflet||!visible.length)return;const b=L.latLngBounds(visible.map(pointLatLng));if(b.isValid())leaflet.fitBounds(b,{padding:[70,70],maxZoom:(definition.documentMap||sourcePixelMap())?0.5:5,animate:false});
}
async function focusEmbeddedObjective(objectiveId){
 if(!embedded||!leaflet)return;
 const token=++focusSequence;
 for(const p of focusedQuestPoints)if(markers.has(p.id))updateMarker(p,false);focusedQuestPoints=[];
 const targets=pois.filter(p=>!p.hiddenByDefault&&p.category==='quest'&&p.questId===questFilter&&p.objectiveId===objectiveId);
 if(!targets.length){closeDetail();fitQuestPoints();return;}
 if(currentFloor!=='all'&&targets.some(p=>p.floor!==currentFloor))await changeFloor('all');
 if(token!==focusSequence)return;
 await showPoi(targets[0],targets.length===1);
 if(token!==focusSequence)return;
 focusedQuestPoints=targets;
 if(targets.length>1){for(const p of targets)if(markers.has(p.id))updateMarker(p,true);leaflet.fitBounds(L.latLngBounds(targets.map(pointLatLng)),{padding:[70,70],maxZoom:(definition.documentMap||sourcePixelMap())?1:5,animate:false});}
}
function closeDetail() {
  const restore=$('raid-detail').contains(document.activeElement);
  if (selected && markers.has(selected.id)) updateMarker(selected,false);
  selected = null; $('raid-detail').hidden = true; outlineLayer?.clearLayers(); if (definition) setUrl();
  document.querySelector('.raid-main').classList.remove('poi-open');
  if(restore)$('raid-map').focus({preventScroll:true});
}
function looseView(p){
  if(p.id&&looseViews.has(p.id))return looseViews.get(p.id);
  const view=loosePresentation(p,itemCatalog,activeLooseSelection,$('raid-search').value);
  if(p.id)looseViews.set(p.id,view);return view;
}
function pointTitle(p){return p.category==='loose-loot'?looseView(p).title:titleOf(p);}
function itemImage(item,cls=''){
  const img=el('img','',cls);img.src=item.icon;img.alt='';img.dataset.item=item.id;img.draggable=false;img.width=24;img.height=24;img.loading='lazy';img.decoding='async';
  img.onerror=()=>{img.onerror=null;img.src=LOOSE_FALLBACK_ICON;};return img;
}
function iconImage(p, cls='') {
  if(p.category==='loose-loot')return itemImage(looseView(p).item,cls);
  const img=el('img','',cls);img.src=iconPath(p);img.alt='';img.draggable=false;img.width=24;img.height=24;return img;
}
function markerIcon(p,active=false) {
  const wrapper=el('span','','raid-pin-art');wrapper.append(iconImage(p));
  if(p.category==='loose-loot'){const view=looseView(p);wrapper.classList.add('is-loose-item');if(view.extra){const badge=el('span','+'+view.extra,'raid-pin-extra');badge.setAttribute('aria-hidden','true');wrapper.append(badge);}}
  if(checked(p))wrapper.append(el('span','✓','raid-pin-check'));
  return L.divIcon({html:wrapper,className:`raid-pin${active?' is-selected':''}${checked(p)?' is-checked':''}`,iconSize:[iconSize,iconSize],iconAnchor:[iconSize/2,iconSize/2],tooltipAnchor:[0,-iconSize/2]});
}
function markerPriority(p) {return p.category.startsWith('extract')?30000:p.category.startsWith('spawn')?20000:p.category==='loot'?0:10000;}
function updateMarker(p,active) {
  markers.get(p.id)?.setIcon(markerIcon(p,active)).setZIndexOffset(active?100000:markerPriority(p));
}
async function loadArtwork() {
  const token = ++artworkId, id = requestId;
  const floor = definition.floors.find(f => f.id === currentFloor);
  const source=sourcePixelMap();const asset=definition.documentMap?{type:'image',path:definition.documentMap.path}:source?{type:'image',path:source.path}:floor?.asset||definition.baseAsset;
  let next;
  if (asset.type === 'svg') {
    if (!svgCache.has(asset.path)) { const r = await fetch(asset.path); if (!r.ok) throw new Error('Не удалось загрузить схему'); svgCache.set(asset.path,await r.text()); }
    if (token !== artworkId || id !== requestId) return;
    const svg = new DOMParser().parseFromString(svgCache.get(asset.path),'image/svg+xml').documentElement;
    if (svg.localName !== 'svg' || svg.querySelector('parsererror')) throw new Error('Ошибка в схеме карты');
    // Same authored bounds as Navigator: SVG letterboxing would move the artwork
    // while keeping markers in place, increasingly visible when zooming in.
    svg.setAttribute('preserveAspectRatio','none');
    const base = definition.baseAsset.baseLayer;
    for (const child of [...svg.children]) {
      if (child.localName !== 'g' || !child.id) continue;
      const isBase = child.id === base || child.getAttribute('data-keep-with-group') === base;
      const show = isBase || child.id === floor?.svgLayer;
      child.style.display = show ? '' : 'none';
      child.style.opacity = isBase && floor?.svgLayer && floor.svgLayer !== base ? '.42' : '1';
    }
    next = L.svgOverlay(svg,bounds(definition.svgBounds || definition.bounds),{interactive:false,pane:'artwork'});
  } else if(asset.type==='tiles') {
    next=rasterTileLayer(L,asset.path,{tileSize:asset.tileSize||256,minNativeZoom:asset.minNativeZoom??definition.minZoom,maxNativeZoom:asset.maxNativeZoom??definition.maxZoom,maxZoom:8,noWrap:true,bounds:bounds(definition.bounds),pane:'artwork',crossOrigin:false});
    let failed=0,loaded=0;
    next.on('tileerror',()=>{if(token!==artworkId||id!==requestId)return;failed++;if(!loaded)$('raid-floor-note').textContent='Не удалось загрузить схему этажа. Координаты точек сохранены. Попробуйте другой этаж или альтернативную схему.';});
    next.on('tileload',()=>{if(token!==artworkId||id!==requestId)return;loaded++;if(failed)$('raid-floor-note').textContent='Часть карты ещё загружается.';});
    next.on('load',()=>{if(token===artworkId&&id===requestId&&loaded&&!failed)$('raid-floor-note').textContent=currentFloor==='all'?'Все точки показаны на основном этаже. Выберите этаж для просмотра его объектов.':'';});
    if(definition.baseAsset.type==='svg'){
      const baseAsset=definition.baseAsset;
      if(!svgCache.has(baseAsset.path)){const response=await fetch(baseAsset.path);if(!response.ok)throw new Error('Не удалось загрузить основной план');svgCache.set(baseAsset.path,await response.text());}
      if(token!==artworkId||id!==requestId)return;
      const baseSvg=new DOMParser().parseFromString(svgCache.get(baseAsset.path),'image/svg+xml').documentElement;
      baseSvg.setAttribute('preserveAspectRatio','none');
      for(const child of [...baseSvg.children])if(child.localName==='g'&&child.id)child.style.display=child.id===baseAsset.baseLayer?'':'none';
      next=L.layerGroup([L.svgOverlay(baseSvg,bounds(definition.svgBounds || definition.bounds),{interactive:false,pane:'artwork'}),next]);
    }
  } else {
    await new Promise((resolve,reject) => { const image = new Image(); image.onload = resolve; image.onerror = () => reject(new Error('Не удалось загрузить изображение этажа')); image.src = asset.path; });
    if (token !== artworkId || id !== requestId) return;
    next = L.imageOverlay(asset.path,(definition.documentMap||sourcePixelMap())?artworkBounds():bounds(asset.bounds || definition.bounds),{interactive:false,pane:'artwork'});
  }
  if (artwork) artwork.remove(); artwork = next.addTo(leaflet); artwork.bringToBack?.();
  $('raid-floor-note').textContent = definition.documentMap
    ? (definition.documentMap.note || 'Альтернативная схема. Объекты этажей можно отфильтровать.')
    : floor && !floor.artworkAvailable
    ? 'Отдельной схемы этажа нет: его объекты показаны на основном плане.'
    : currentFloor === 'all' && definition.floors.length ? 'Объекты всех этажей на основном плане. Для точного просмотра выберите этаж.'
    : '';
}
async function changeFloor(value) {
  const valid = [definition.baseFloor.id,'all',...definition.floors.map(f=>f.id)];
  value=definition.floorAliases?.[value]||value;currentFloor = valid.includes(value) ? value : 'all'; $('raid-floor').value = currentFloor; closeDetail();
  render(); setUrl();savePrefs();
  try { await loadArtwork(); drawRoute(); } catch (e) { notify(e.message); }
}
async function setLocation(id, {initial = false} = {}) {
  const sourceMap = maps.find(m => m.id === id); if (!sourceMap) return; const useDocuments=artworkMode==='documents'&&sourceMap.documentMap;
  const next={...sourceMap,...(useDocuments?sourceMap.documentFloorDefinition:{}),floorPolicy:useDocuments?undefined:sourceMap.floorPolicy,documentMap:useDocuments?sourceMap.documentMap:undefined};
  const token = ++requestId; ++artworkId;focusReceiver.wait();++focusSequence;focusedQuestPoints=[];
  $('raid-loading').hidden = false; $('raid-loading').textContent = 'Загрузка карты…';
  try {
    const dataKey=gameMode+':'+id; if (!dataCache.has(dataKey)) dataCache.set(dataKey, await json(gameMode==='pve'?`data/raid/pve/${id}.json`:next.poiPath));
    if (token !== requestId) return;
    definition = next; selected = null; $('raid-detail').hidden = true;document.querySelector('.raid-main').classList.remove('poi-open');
    const payload=localizeDisplay(dataCache.get(dataKey));next.mapInfo=payload.mapInfo;
    pois = scopedRaidPoints(payload,id,gameMode).filter(p => validPosition(p.position) && CATEGORIES[p.category]).map(p => ({...p,floor:poiFloor(next,p)}));
    for (const p of pois) if (p.lootType && !(p.lootType in LOOT_NAMES)) lootTypes.add(p.lootType);
    currentFloor = initial && params.has('floor') ? params.get('floor') : initial && questFilter ? 'all' : (read(prefsKey)?.floors?.[id+':'+artworkMode]||'all');
    currentFloor=next.floorAliases?.[currentFloor]||currentFloor;if (!['all',next.baseFloor.id,...next.floors.map(f=>f.id)].includes(currentFloor)) currentFloor = 'all';
    $('raid-total').textContent=maps.length+' локаций · '+(gameMode==='pve'?'PVE':'PVP');$('raid-artwork').value=artworkMode;$('raid-mode').value=gameMode;
    $('raid-map-select').value = id; $('raid-title').textContent = next.name; document.title = `${next.name} — Интерактивные карты — Escape from Tarkov Wiki`;
    $('raid-map-credit').textContent = next.documentMap ? 'Альтернативная схема' : 'Интерактивная карта';
    $('raid-labels').closest('label').hidden=Boolean(next.documentMap);
    $('raid-floor').replaceChildren(...[{id:'all',name:'Все этажи'},...new Map([next.baseFloor,...next.floors].map(f=>[f.id,f])).values()].map(f => { const o = el('option',f.name);o.value=f.id;return o; }));
    $('raid-floor').value = currentFloor; listLimit = 100;
    if (leaflet) leaflet.remove(); artwork = null;
    leaflet = L.map('raid-map',{crs:(next.documentMap||next.sourceCalibrations)?L.CRS.Simple:createCrs(next),minZoom:(next.documentMap||next.sourceCalibrations)?-4:-2,maxZoom:(next.documentMap||next.sourceCalibrations)?3:8,zoomSnap:.25,zoomDelta:.5,preferCanvas:false,zoomControl:false,attributionControl:false,scrollWheelZoom:true});
    // Dedicated panes prevent asynchronous artwork/floor changes from covering markers.
    for(const [name,z] of [['artwork',200],['zones',410],['landmarks',450],['poi',600]]){
      const pane=leaflet.createPane(name);pane.style.zIndex=String(z);if(name!=='poi')pane.style.pointerEvents='none';
    }
    L.control.zoom({position:'topright',zoomInTitle:'Приблизить',zoomOutTitle:'Отдалить'}).addTo(leaflet);
    markerLayer = L.layerGroup().addTo(leaflet); outlineLayer = L.layerGroup().addTo(leaflet); labelsLayer = L.layerGroup().addTo(leaflet); routeLayer=L.layerGroup().addTo(leaflet);
    leaflet.on('click',closeDetail); fit();await loadArtwork();
    // Даём браузеру показать подложку до построения списков и множества маркеров.
    await new Promise(resolve=>requestAnimationFrame(()=>setTimeout(resolve,0)));if(token!==requestId)return;
    buildFilters();render();drawRoute();
    if (token !== requestId) return;
    $('raid-loading').hidden = true;
    if (initial && params.has('poi')) { const p = pois.find(p=>p.id===params.get('poi')); if (p) await showPoi(p,true); }
    if(embedded){fitQuestPoints();if(initial&&params.get('objective'))await focusEmbeddedObjective(params.get('objective'));}savePrefs();setUrl();focusReceiver.ready();
  } catch (e) {
    if (token !== requestId) return;
    $('raid-loading').replaceChildren(el('span',e.message + '. Проверьте соединение.'));
    const retry = el('button','Повторить','button');retry.onclick = () => setLocation(id,{initial});$('raid-loading').append(retry);
  }
}
function buildFilters() {
  if(!definition||!looseFilter)return;looseFilter.setContext(gameMode,definition.id,pois);looseFilter.root.hidden=!categories.has('loose-loot');
  const counts = new Map(); for (const p of pois) counts.set(p.category,(counts.get(p.category)||0)+1);
  $('raid-categories').replaceChildren(...Object.entries(CATEGORIES).map(([id,[name,color,glyph]])=>{
    const label = el('label','','raid-category'); label.style.setProperty('--category',color);
    const input = el('input');input.type='checkbox';input.checked=categories.has(id);input.dataset.category=id;
    input.onchange = () => { input.checked ? categories.add(id) : categories.delete(id);if(id==='loose-loot')looseFilter.root.hidden=!input.checked;render();savePrefs(); };
    label.append(input,iconImage({category:id},'category-icon'),el('span',name,'category-name'),el('span',String(counts.get(id)||0),'category-count'));if(id==='loose-loot'){const group=el('div','','raid-loose-category');group.append(label,looseFilter.root);return group;}return label;
  }));
  const types = [...new Set(pois.filter(p=>p.category==='loot').map(p=>p.lootType))].sort((a,b)=>(LOOT_NAMES[a]||a).localeCompare(LOOT_NAMES[b]||b,'ru'));
  $('loot-type-count').textContent = types.length;
  $('raid-loot-types').replaceChildren(...types.map(type=>{
    const label = el('label','','raid-loot-type');const input=el('input');input.type='checkbox';input.checked=lootTypes.has(type);input.dataset.loot=type;
    input.onchange=()=>{input.checked?lootTypes.add(type):lootTypes.delete(type);render();savePrefs();};
    label.append(input,iconImage({category:'loot',lootType:type},'loot-type-icon'),el('span',LOOT_NAMES[type]||type),el('span',String(pois.filter(p=>p.lootType===type).length),'category-count'));return label;
  }));
}
function render() {
  if (!definition) return;
  activeLooseSelection=loosePrefs.selected(gameMode,definition.id);looseViews.clear();
  const available=pois.filter(p=>(!embedded||!p.hiddenByDefault)&&(!p.hiddenByDefault||$('raid-legacy').checked||checked(p)||p.id===params.get('poi')||(questFilter&&p.questId===questFilter))&&(!questFilter||p.questId===questFilter)&&(!objectiveFilter||p.objectiveId===objectiveFilter));
  visible = filterPois(available,{categories,lootTypes,looseItems:activeLooseSelection,looseCatalog:itemCatalog,query:$('raid-search').value,floor:currentFloor,status:$('raid-status').value,checked:new Set(pois.filter(checked).map(p=>p.id))});
  $('raid-quest-filter').hidden=!questFilter;
  if(questFilter){$('raid-quest-name').textContent=questIndex[gameMode]?.[questFilter]?.name||'Выбранное задание';$('raid-quest-link').href=siteHref(`quests.html?quest=${encodeURIComponent(questFilter)}&mode=${gameMode}`);}
  $('raid-visible').textContent = embedded?`${visible.length} точек задания`:`${visible.length.toLocaleString('ru')} из ${pois.length.toLocaleString('ru')} объектов`;
  $('raid-list-count').textContent = visible.length;
  markerLayer.clearLayers(); markers = new Map();
  // Image markers occupy their own pane above every raster, SVG and outline.
  for (const p of visible) {
    const marker = L.marker(pointLatLng(p),{poiId:p.id,icon:markerIcon(p,selected?.id===p.id),pane:'poi',keyboard:true,autoPanOnFocus:false,bubblingMouseEvents:false,riseOnHover:true,riseOffset:110000,zIndexOffset:selected?.id===p.id?100000:markerPriority(p),alt:`${pointTitle(p)} · ${floorName(p.floor)}`});
    const tip=el('div','','raid-tooltip-content');tip.append(el('strong',pointTitle(p)),el('span',CATEGORIES[p.category][0]+' · '+floorName(p.floor)));
    bindMapTooltip(marker,tip);
    marker.on('click',e=>{L.DomEvent.stopPropagation(e);showPoi(p,false);});marker.addTo(markerLayer);marker.getElement()?.setAttribute('aria-label',`${pointTitle(p)} · ${floorName(p.floor)}`);markers.set(p.id,marker);
  }
  renderList(); renderLabels();
}
function renderLabels() {
  labelsLayer.clearLayers(); if (definition.documentMap || !$('raid-labels').checked) return;
  for (const label of definition.labels || []) {
    const wrapper=el('div');wrapper.append(el('span',label.text));
    L.marker([label.position[1],label.position[0]],{interactive:false,keyboard:false,pane:'landmarks',icon:L.divIcon({className:'raid-landmark',html:wrapper,iconSize:[0,0]})}).addTo(labelsLayer);
  }
}
function renderList() {
  $('raid-list').replaceChildren(...visible.slice(0,listLimit).map(p=>{
    const b=el('button','','raid-list-item');b.style.setProperty('--category',CATEGORIES[p.category][1]);b.classList.toggle('active',selected?.id===p.id);
    const text=el('div');text.append(el('strong',pointTitle(p)),el('small',`${CATEGORIES[p.category][0]} · ${floorName(p.floor)}${checked(p)?' · ✓':''}`));
    b.append(iconImage(p,'list-icon'),text);b.onclick=()=>{setSidebar(false);showPoi(p,true);};return b;
  }));
  if (!visible.length) $('raid-list').append(el('p',questFilter?'У выбранной цели нет точных координат на этой локации или точки скрыты фильтрами. Условия задания доступны по ссылке выше.':'Нет подходящих объектов. Измените этаж, запрос или включённые слои.','raid-empty'));
  $('raid-more').hidden = visible.length<=listLimit;
}
async function loadPointMedia(p,root,description){
 const mode=gameMode,request=requestId,key=mode+':'+p.questId;
 root.replaceChildren(el('p','Загружаем иллюстрации задания…'));
 try{
  if(!pointMedia.has(key)){
   if(pointMedia.size>=20)pointMedia.delete(pointMedia.keys().next().value);
   const pending=pageDataset('quest-'+key,`data/guide/quests-${mode}.json`,{view:'detail',id:p.questId}).then(async bundle=>{
    const task=bundle.tasks.find(task=>task.id===p.questId);if(!task)throw Error('Иллюстрации задания недоступны.');
    return bundle.cms?{task,frames:questGalleryPlan(task).frames}:{task,extras:await questExtras()};
   }).catch(error=>{pointMedia.delete(key);throw error;});pointMedia.set(key,pending);
  }
  const value=await pointMedia.get(key);if(request!==requestId||mode!==gameMode||selected?.id!==p.id||!root.isConnected)return;
  const exact=value.frames?.filter(frame=>frame.objectiveIds?.includes(p.objectiveId)),pictures=value.extras?mediaFor(value.extras,p.questId,p.objectiveId):{images:exact.length?exact:value.frames,association:exact.length?'objective':'quest'};
  const translated=value.task.objectives?.find(goal=>goal.id===p.objectiveId)?.description||value.extras?.patches?.objectives?.[p.objectiveId];
  if(translated&&/[А-ЯЁа-яё]/.test(translated)&&!/[А-ЯЁа-яё]/.test(p.description||''))description.textContent=translated;
  root.replaceChildren();if(pictures.images.length)root.append(galleryBlock(pictures.images,{compact:true,association:pictures.association,title:pictures.association==='objective'?'Где выполнить эту цель':'Скриншоты задания'}));
 }catch(error){if(request===requestId&&selected?.id===p.id&&root.isConnected)root.replaceChildren(el('p','Иллюстрации пока недоступны.'),link('Открыть задание',siteHref(`quests.html?quest=${encodeURIComponent(p.questId)}&mode=${mode}`)));}
}
async function showPoi(p, center = false) {
  const focus=selected?.id!==p.id||$('raid-detail').hidden;
  markers.get(p.id)?.closeTooltip();
  if (selected && markers.has(selected.id)) updateMarker(selected,false);
  if (center && currentFloor !== 'all' && currentFloor !== p.floor) await changeFloor(p.floor);
  selected = p; outlineLayer.clearLayers();
  updateMarker(p,true);
  if (p.outline?.length) {
    const vertices=definition.documentMap&&p.documentOutline?p.documentOutline.map(([x,y])=>[-y,x]):p.outline.filter(validPosition).map(v=>pointLatLng({position:v,floor:p.floor}));
    L.polygon(vertices,{color:CATEGORIES[p.category][1],weight:2,fillOpacity:.16,interactive:false,pane:'zones'}).addTo(outlineLayer);
  }
  $('raid-detail').hidden=false;$('poi-category').textContent=CATEGORIES[p.category][0];$('poi-title').textContent=pointTitle(p);$('poi-floor').textContent=floorName(p.floor);
  document.querySelector('.raid-main').classList.add('poi-open');
  const info=$('poi-info');info.replaceChildren();
  const description=el('p',p.description||'');if(p.description)info.append(description);
  if(p.displayPositionMeaning&&definition.documentMap)info.append(el('p',p.displayPositionMeaning));if(p.coordinateNote)info.append(el('p',p.coordinateNote));if(p.coordinateSource)info.append(link('Источник координат ↗',p.coordinateSource));else if(p.positionCorrectionSource?.url)info.append(link('Источник уточнения точки ↗',p.positionCorrectionSource.url));if(p.storySourceUrl)info.append(link('Источник сюжетной точки ↗',p.storySourceUrl));
  if(p.sourceNote)info.append(el('p',publicAttribution(p.sourceNote)));else if(p.hiddenByDefault)info.append(el('p','Неподтверждённая точка или вариант другой записи. Сохранена для ваших прежних отметок.'));
  if(p.questId){const media=el('div');info.append(media);loadPointMedia(p,media,description);const a=el('a','Открыть задание: условия, цепочка и награды','button');a.href=siteHref(`quests.html?quest=${encodeURIComponent(p.questId)}&mode=${gameMode}`);info.append(a);}
  for(const condition of p.conditions||[])info.append(el('p',condition));
  if(p.trader)info.append(el('p',`Торговец: ${p.trader} · Уровень от ${p.minLevel}`));
  if(p.category==='loot'||p.category==='loose-loot')info.append(el('p','Возможное место лута. Наличие и содержимое меняются между рейдами.'));
  if(p.kind==='boss-zone')info.append(el('p',`Шанс спавна: ${Math.round(p.spawnChance*100)}%. Данные от ${p.snapshotDate||definition.snapshotDate}; текущий шанс может отличаться.`));
  if(p.kind==='extract')info.append(el('p','Доступность выхода и условия проверьте в текущем рейде.'));
  if(p.transferItem){const id=p.transferItem.itemId;info.append(el('p',`Требуется: ${id==='5449016a4bdc2d6f028b456f'?'Рубли':itemNames[id]||'предмет для выхода'} × ${p.transferItem.count.toLocaleString('ru')}`));}
  for(const id of p.keyIds||[])info.append(link(itemNames[id]||'Информация о ключе ↗',siteHref(`reference.html?tab=items&item=${encodeURIComponent(id)}&mode=${gameMode}`)),el('br'));
  if(p.category==='loose-loot'){
    info.append(el('h3','Возможные предметы'));const items=el('div','','loose-point-items');
    for(const id of looseItemIds(p)){const item=looseItemRecord(id,itemCatalog),a=el('a','','loose-point-item');a.href=siteHref(`reference.html?tab=items&item=${encodeURIComponent(id)}&mode=${gameMode}`);a.append(itemImage(item),el('span',item.name));items.append(a);}
    info.append(items);
  }else for(const id of p.itemIds||[])info.append(link(p.itemNames?.[p.itemIds.indexOf(id)]||itemNames[id]||'Предмет ↗',siteHref(`reference.html?tab=items&item=${encodeURIComponent(id)}&mode=${gameMode}`)),el('br'));
  const connections = p.switchIds || (p.activates||[]).map(a=>a.targetId);
  for(const id of connections){const target=pois.find(x=>x.id===id);if(target){const b=el('button',`Связанный объект: ${titleOf(target)}`,'text-button');b.onclick=()=>showPoi(target,true);info.append(b);}}
  if(!p.inferredWorld)info.append(el('small',`Высота по данным карты: ${p.position.y.toFixed(1)} м`));
  $('poi-checked').textContent=checked(p)?'✓ Отмечено · снять отметку':'Отметить посещённым';$('poi-checked').setAttribute('aria-pressed',String(checked(p)));
  $('poi-note').value=typeof saved.notes[keyOf(p)]==='string'?saved.notes[keyOf(p)]:'';
  const screen=leaflet.latLngToContainerPoint(pointLatLng(p));
  const neighbors=visible.filter(x=>x.id!==p.id&&leaflet.latLngToContainerPoint(pointLatLng(x)).distanceTo(screen)<iconSize*.8);
  $('poi-overlap').replaceChildren();
  if(neighbors.length){$('poi-overlap').append(el('span',`Метки рядом / под значком: ${neighbors.length}`));for(const other of neighbors){const b=el('button',`${pointTitle(other)} · ${floorName(other.floor)}`);b.prepend(iconImage(other));b.onclick=()=>showPoi(other,false);$('poi-overlap').append(b);}}
  if(center||focus&&compactRaid())focusPoint(p);
  setUrl();renderList();
  if(focus){$('raid-detail').scrollTop=0;$('poi-close').focus({preventScroll:true});}
}

function drawRoute(){
  routeLayer?.clearLayers();if(!routeId||!btrReference)return;
  const info=btrReference.maps[definition.id],route=info?.routes.find(r=>r.id===routeId);if(!route)return;
  const convert=p=>pointLatLng({position:{x:p.x,y:0,z:p.z},floor:definition.baseFloor.id});
  L.polyline(route.path.map(convert),{color:'#f2ce78',weight:4,opacity:.85,interactive:false,pane:'zones'}).addTo(routeLayer);
  for(const stop of info.stops){const p={id:'btr-reference-'+stop.id,category:'btr',kind:'btr',name:stop.name,position:stop.position,floor:definition.baseFloor.id,description:'Справочная остановка БТР. Снимок NepPure для режима PVE от 14.09.2026; маршрут и время в вашем рейде могут отличаться.'};const marker=L.marker(convert(stop.position),{icon:markerIcon(p),pane:'poi',autoPanOnFocus:false,bubblingMouseEvents:false,alt:stop.name});const tip=el('div','','raid-tooltip-content');tip.append(el('strong',stop.name),el('span','Остановка БТР'));bindMapTooltip(marker,tip);marker.on('click',e=>{L.DomEvent.stopPropagation(e);showPoi(p,false);});marker.addTo(routeLayer);marker.getElement()?.setAttribute('aria-label',stop.name+' · Остановка БТР');}
  $('raid-floor-note').textContent='Маршрут БТР '+route.label+' · справочный снимок PVE 14.09.2026, время ±60 с';
}

async function start() {
  new ResizeObserver(()=>{updateSidebarAccess();leaflet?.invalidateSize({animate:false,pan:false});if(selected&&compactRaid())focusPoint(selected);}).observe($('raid-map'));
  if(!globalThis.L)throw new Error('Не удалось загрузить модуль карты');
  const [sourceMaps,names,calibration,qIndex,btr,looseCatalog]=await loadContentBundle('raid-base');itemCatalog=looseCatalog.items;questIndex=qIndex;btrReference=btr;
  maps=localizeDisplay(sourceMaps).map(m=>({...m,documentMap:calibration[m.id]}));itemNames=names;
  looseFilter=new LooseLootFilter($('raid-loose-details'),{catalog:itemCatalog,preferences:loosePrefs,image:itemImage,onChange:render,onPersist:savePrefs});
  const mall=maps.find(m=>m.id==='interchange');if(mall){mall.baseFloor={...mall.baseFloor,name:'Территория / паркинг'};mall.floors=mall.floors.map(f=>({...f,name:f.id==='First_Floor'?'1 этаж торгового центра':'2 этаж торгового центра'}));}
  $('raid-map-select').replaceChildren(...maps.map(m=>{const o=el('option',m.name);o.value=m.id;return o;}));
  $('raid-total').textContent=`${maps.length} локаций`;
  $('raid-artwork').onchange=e=>{artworkMode=e.target.value;savePrefs();setLocation(definition.id);};
  $('raid-mode').onchange=e=>{gameMode=e.target.value;try{storePersonal('kord-atlas-game-mode',gameMode);}catch{}setLocation(definition.id);};
  $('raid-legacy').onchange=()=>{render();savePrefs();};
  $('raid-quest-clear').onclick=()=>{questFilter='';objectiveFilter='';categories.clear();Object.keys(CATEGORIES).forEach(k=>categories.add(k));buildFilters();render();setUrl();};
  $('raid-map-select').onchange=e=>{setLocation(e.target.value);setSidebar(false);};
  $('raid-floor').onchange=e=>changeFloor(e.target.value);
  $('raid-search').oninput=()=>{listLimit=100;tab('list');render();savePrefs();};
  $('raid-status').onchange=()=>{render();savePrefs();};$('raid-labels').onchange=()=>{renderLabels();savePrefs();};
  $('raid-more').onclick=()=>{listLimit+=150;renderList();};
  $('raid-show-all').onclick=()=>{if(!definition)return;loosePrefs.all(gameMode,definition.id);Object.keys(CATEGORIES).forEach(k=>categories.add(k));for(const p of pois)if(p.lootType)lootTypes.add(p.lootType);buildFilters();render();savePrefs();};
  $('raid-hide-all').onclick=()=>{categories.clear();buildFilters();render();savePrefs();};
  $('raid-reset').onclick=()=>{if(!definition)return;loosePrefs.all(gameMode,definition.id);loosePrefs.update(gameMode,definition.id,{query:''});categories.clear();Object.keys(CATEGORIES).forEach(k=>categories.add(k));for(const p of pois)if(p.lootType)lootTypes.add(p.lootType);$('raid-search').value='';$('raid-status').value='all';listLimit=100;buildFilters();changeFloor('all');savePrefs();};
  for(const name of ['filters','list']){
    $('raid-'+name+'-tab').onclick=()=>tab(name);
    $('raid-'+name+'-tab').onkeydown=e=>{if(['ArrowLeft','ArrowRight'].includes(e.key)){e.preventDefault();const next=name==='list'?'filters':'list';tab(next);$('raid-'+next+'-tab').focus();}};
  }
  $('raid-menu').onclick=()=>setSidebar(!$('raid-layout').classList.contains('filters-open'));$('raid-backdrop').onclick=()=>setSidebar(false);
  $('raid-fit').onclick=embedded?fitQuestPoints:fit;$('poi-close').onclick=closeDetail;$('poi-center').onclick=()=>{if(selected)focusPoint(selected);};
  $('raid-icon-size').value=iconSize;$('raid-icon-value').textContent=iconSize;
  $('raid-icon-size').oninput=e=>{iconSize=Number(e.target.value);$('raid-icon-value').textContent=iconSize;render();savePrefs();};
  $('poi-checked').onclick=()=>{if(!selected)return;const p=selected,key=keyOf(p);saved.checked=checked(p)?saved.checked.filter(k=>k!==key):[...saved.checked,key];persist();render();showPoi(p,false);};
  $('poi-note').oninput=e=>{if(!selected)return;const text=e.target.value.slice(0,2000);if(text.trim())saved.notes[keyOf(selected)]=text;else delete saved.notes[keyOf(selected)];persist();};
  $('poi-copy').onclick=async()=>{try{await navigator.clipboard.writeText(location.href);notify('Ссылка на объект скопирована');}catch{window.prompt('Ссылка на объект',location.href);}};
  $('raid-about').onclick=()=>$('raid-about-dialog').showModal();$('raid-about-close').onclick=()=>$('raid-about-dialog').close();
  $('raid-fullscreen').onclick=async()=>{try{if(document.fullscreenElement)await document.exitFullscreen();else await document.documentElement.requestFullscreen();}catch{notify('Полноэкранный режим недоступен в этом браузере.');}};
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){leaflet?.eachLayer(layer=>layer.closeTooltip?.());setSidebar(false);closeDetail();}if(e.target.closest?.('input,textarea,select,dialog'))return;if(e.key==='/'&&!embedded){e.preventDefault();setSidebar(true);$('raid-search').focus();}if(e.key==='0')fit();});
  window.addEventListener('wiki:state',e=>{if(e.detail.key===prefsKey&&definition){loosePrefs.value=structuredClone(read(prefsKey)?.looseLoot||{});looseFilter.setContext(gameMode,definition.id,pois);render();}if(e.detail.key===progressKey){const next=read(progressKey);if(next&&Array.isArray(next.checked)&&next.notes&&typeof next.notes==='object'){saved=next;render();if(selected)showPoi(selected,false);}}});
  $('raid-search').value=typeof prefs.query==='string'?prefs.query:'';$('raid-status').value=['all','remaining','checked'].includes(prefs.status)?prefs.status:'all';$('raid-labels').checked=prefs.labels!==false;$('raid-legacy').checked=prefs.legacy===true;tab(prefs.tab==='list'?'list':'filters');
  if(params.has('q'))$('raid-search').value=params.get('q');if(params.has('category')&&CATEGORIES[params.get('category')]){categories.clear();categories.add(params.get('category'));}if(questFilter){categories.clear();categories.add('quest');$('raid-search').value='';$('raid-status').value='all';}
  const id=params.get('map')||prefs.map||'shoreline';await setLocation(maps.some(m=>m.id===id)?id:'shoreline',{initial:true});
}
await start();
