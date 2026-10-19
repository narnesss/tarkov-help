import {siteHref,pageParams,canonicalURL,usesPageRoutes} from './site-routes.mjs?v=page-content-20261017';
import {readPersonal,writePersonal} from './profile-store.mjs?v=loading-20261018';
const storePersonal=(key,value)=>{if(!writePersonal(key,value))throw new Error('Локальное сохранение недоступно');};
import { STORAGE_KEY, FLOOR_NAMES, filterSpawns, sanitizeProgress, zoomTransform, hasCoordinates } from './model.mjs?v=page-content-20261017';
import {documentViewport,documentPointPosition,documentLevelSvg,documentFloors} from './document-map.mjs?v=page-content-20261017';

import {image} from './guide-common.mjs?v=documents-access-20261020';
import {accessLabel,visibleDocumentExits,createDocumentOverlayLoader,fitDocumentOverlayView,documentOverlayFloors} from './document-overlays.mjs?v=documents-exits-20261020';

const $ = id => document.getElementById(id);
const compactViewport = () => innerWidth <= 1200;
const documentIconSize = value => typeof value === 'number' && Number.isFinite(value)
  ? Math.max(16, Math.min(36, Math.round(value / 2) * 2)) : 24;
const node = (tag, className = '', text = '') => {
  const element = document.createElement(tag);
  if (className) element.className = className;
  if (text) element.textContent = text;
  return element;
};
const icon = name => {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
  use.setAttribute('href', '#i-' + name);
  svg.setAttribute('aria-hidden', 'true');
  svg.append(use);
  return svg;
};
const shortNames = {
  finansovaea: 'Финансовая', personal: 'Личные данные ЧВК', proectnaea: 'Проектная',
  tehnicheskaea: 'Чертежи и техническая', testovaea: 'Тестовая', polzovatelskoe: 'Пользовательская',
  meditsinskaea: 'Медицинская', expluatationnaea: 'Эксплуатационная',
};
let toastTimer;
function toast(message) {
  clearTimeout(toastTimer);
  $('toast').textContent = message;
  $('toast').hidden = false;
  toastTimer = setTimeout(() => { $('toast').hidden = true; }, 4200);
}
function safeRead(key) {
  return readPersonal(key);
}
async function fetchData(path) {
  const {contentResponse}=await import('./content-client.mjs?v=documents-access-20261020');
  const content=await contentResponse(path);if(content)return content.json();
  const response = await fetch(path);
  if (!response.ok) throw new Error('Не удалось загрузить ' + path);
  return response.json();
}

async function start() {
  const [maps, docs, allSpawns, source] = await Promise.all(['maps', 'docs', 'spawns', 'source'].map(name => fetchData(`data/${name}.json`)));
  const spawns = allSpawns.filter(s => !s.cmsArchived && maps.some(map=>map.id===s.map));
  const restoreProgress = value => sanitizeProgress(value, spawns, source.retiredPointIds || []);
  const mapById = new Map(maps.map(m => [m.id, m]));
  const docById = new Map(docs.map(d => [d.id, d]));
  const spawnById = new Map(spawns.map(s => [s.id, s]));
  const numbers = new Map();
  for (const map of maps) spawns.filter(s => s.map === map.id).forEach((s, i) => numbers.set(s.id, i + 1));
  const number = s => String(numbers.get(s.id)).padStart(2, '0');
  const prefsKey = 'kord-atlas-view-v1';
  const prefs = safeRead(prefsKey) || {};
  const params = pageParams();
  const initialPoint = spawnById.get(params.get('point'));
  const initialMap = initialPoint?.map || params.get('map') || prefs.map || 'interchange';
  let progress = { version: 1, found: [], notes: {} };
  const saved = safeRead(STORAGE_KEY);
  if (saved) {
    try { progress = restoreProgress(saved); } catch { toast('Сохранённый прогресс не удалось прочитать. Можно загрузить резервную копию.'); }
  }
  const state = {
    mapId: mapById.has(initialMap) ? initialMap : 'interchange', types: new Set((Array.isArray(prefs.types)?prefs.types:docs.map(d => d.id)).filter(id=>docById.has(id))),
    query: typeof prefs.query==='string'?prefs.query:'', status: ['all','found','remaining'].includes(prefs.status)?prefs.status:'all', floor: 'all', allMaps: prefs.allMaps===true, showAlts: prefs.showAlts !== false, tab: prefs.tab==='points'?'points':'filters',
    found: new Set(progress.found), notes: progress.notes, selected: null, photo: 0, iconSize: documentIconSize(prefs.iconSize),
  };
  let view = { x: 0, y: 0, scale: 1 };
  let baseScale = 1;
  let mapWidth = 2600;
  let mapHeight = 1463;
  let markers = [];
  let overlay=null,overlayMap='',overlayMode=params.get('mode')==='regular'?'regular':params.get('mode')==='pve'?'pve':prefs.exitMode==='regular'?'regular':'pve';
  let pendingFloor=null;
  const overlayLoader=createDocumentOverlayLoader();
  const showExits=$('document-show-exits');showExits.checked=prefs.showExits!==false;
  $('document-exit-mode').value=overlayMode;
  async function loadOverlay(){
    overlay=null;overlayMap=state.mapId;const map=overlayMap,mode=overlayMode;
    $('document-overlay-status').textContent='Загрузка выходов и ключей…';$('document-overlay-retry').hidden=true;render();
    try{const data=await overlayLoader(map,mode);if(!data||state.mapId!==map||overlayMode!==mode)return;
      overlay=data;if(pendingFloor&&documentOverlayFloors(mapSpawns(),mapById.get(state.mapId),overlay).includes(pendingFloor))state.floor=pendingFloor;pendingFloor=null;buildFloors();persistPrefs();$('document-overlay-status').textContent=data.available===false?'Данные выходов пока недоступны.':data.exits.length+' выходов ЧВК. Условия эвакуации уточняйте в рейде.';render();
    }catch{if(state.mapId!==map||overlayMode!==mode)return;$('document-overlay-status').textContent='Не удалось загрузить выходы и ключи.';$('document-overlay-retry').hidden=false;}
  }
  showExits.addEventListener('change',render);
  $('document-exit-mode').addEventListener('change',()=>{overlayMode=$('document-exit-mode').value;setUrl();loadOverlay();});
  $('document-overlay-retry').addEventListener('click',loadOverlay);
  $('document-exit-close').addEventListener('click',()=>$('document-exit-dialog').close());
  let exitFocus;
  $('document-exit-dialog').addEventListener('close',()=>{if(exitFocus?.isConnected)exitFocus.focus({preventScroll:true});});
  function lockBadge(access){const badge=node('span','document-lock');badge.append(icon('lock'));badge.title=accessLabel(access);badge.setAttribute('aria-hidden','true');return badge;}
  function renderAccess(spawn){const box=$('detail-access'),access=overlay?.access?.[spawn.id];box.replaceChildren();box.hidden=!access?.requiresKey;
    if(box.hidden)return;box.append(node('strong','',accessLabel(access)));
    for(const key of access.keys||[]){if(!key.id)continue;const link=node('a','',key.name||'Ключ');link.href=siteHref('reference.html?tab=items&item='+encodeURIComponent(key.id)+'&mode='+overlayMode);box.append(link);}
  }
  function openExit(exit,button){exitFocus=button;$('document-exit-title').textContent=exit.name;
    $('document-exit-conditions').replaceChildren(...(exit.conditions?.length?exit.conditions:['Особые условия в источнике не указаны. Проверьте доступность выхода в рейде.']).map(text=>node('li','',text)));
    $('document-exit-dialog').showModal();
  }
  let visible = [];
  let results = [];
  let lastFocus = null;
  let storageWarningShown = false;
  const artworkCache=new Map();let artworkKey='',artworkRequest=0,artworkUrl=null;

  async function updateMapArtwork() {
    const map=mapById.get(state.mapId),key=map.id+':'+state.floor+':'+(map.layeredFile||map.file);
    if(key===artworkKey)return;artworkKey=key;const request=++artworkRequest;
    if(!map.documentPanels){$('map-image').src=map.file;$('minimap-image').src=map.file;return;}
    $('map-loading').hidden=false;
    $('map-image').hidden=true;
    $('minimap').hidden=true;
    try{
      const path=map.layeredFile||map.file;
      if(!artworkCache.has(path))artworkCache.set(path,fetch(path,{credentials:'omit'}).then(r=>{if(!r.ok)throw Error('Схема недоступна');return r.text();}).catch(error=>{artworkCache.delete(path);throw error;}));
      const source=await artworkCache.get(path);if(request!==artworkRequest)return;
      const rendered=documentLevelSvg(source,map,state.floor),previous=artworkUrl;
      artworkUrl=URL.createObjectURL(new Blob([rendered],{type:'image/svg+xml'}));
      $('map-image').src=artworkUrl;$('minimap-image').src=artworkUrl;
      if(previous)URL.revokeObjectURL(previous);
    }catch(error){if(request!==artworkRequest)return;artworkKey='';$('map-loading').replaceChildren(document.createTextNode('Схема не загрузилась. Обновите страницу.'));$('map-loading').hidden=false;}
  }

  function persist() {
    try {
      storePersonal(STORAGE_KEY, { version: 1, found: [...state.found], notes: state.notes });
      return true;
    } catch {
      if (!storageWarningShown) toast('Браузер не разрешил сохранение. Скачайте прогресс, чтобы не потерять отметки.');
      storageWarningShown = true;
      document.querySelector('.local-caption').textContent = 'Сохранение недоступно — скачайте прогресс';
      return false;
    }
  }
  function persistPrefs() {
    try { storePersonal(prefsKey, { map: state.mapId, showAlts: state.showAlts, query:state.query, status:state.status, floor:state.floor, allMaps:state.allMaps, types:[...state.types], tab:state.tab, iconSize:state.iconSize, artworkVersion:2,showExits:showExits.checked,exitMode:overlayMode }); } catch { /* Progress export remains available. */ }
  }
  function applyIconSize() {
    $('document-icon-size').value = state.iconSize;
    $('document-icon-value').textContent = state.iconSize;
    $('marker-layer').style.setProperty('--document-marker-size', state.iconSize + 'px');
  }
  function setUrl() {
    const url = new URL(usesPageRoutes()?'documents.html':location.href,location.origin+'/');
    url.search = '';
    url.searchParams.set('map', state.mapId);
    url.searchParams.set('mode',overlayMode);
    if (state.selected) url.searchParams.set('point', state.selected.id);
    history.replaceState(null, '', canonicalURL(url));
  }
  function chooseTab(tab) {
    state.tab=tab;persistPrefs();
    for (const name of ['filters', 'points']) {
      const active = name === tab;
      $(name + '-tab').classList.toggle('active', active);
      $(name + '-tab').setAttribute('aria-selected', String(active));
      $(name + '-tab').tabIndex = active ? 0 : -1;
      $(name + '-panel').hidden = !active;
    }
  }
  function setSidebar(open) {
    const restoreFocus=!open&&$('sidebar').contains(document.activeElement);
    $('app-layout').classList.toggle('sidebar-open', open);
    $('sidebar-backdrop').hidden = !open;
    $('menu-button').setAttribute('aria-expanded', String(open));
    $('menu-button').setAttribute('aria-label', open ? 'Закрыть фильтры' : 'Открыть фильтры и список точек');
    updateSidebarAccess();
    if (restoreFocus) $('menu-button').focus({preventScroll:true});
  }
  function updateSidebarAccess() {
    const mobile=compactViewport(),open=$('app-layout').classList.contains('sidebar-open');
    $('sidebar').inert=mobile&&!open;
    $('map-area').inert=mobile&&open;
    $('sidebar-backdrop').hidden=!mobile||!open;
  }
  function mapSpawns() { return spawns.filter(s => s.map === state.mapId); }

  $('dataset-count').textContent = `${maps.length} локаций · ${spawns.length} точек`;
  $('about-maps').textContent = maps.length;
  $('about-spawns').textContent = spawns.length;
  $('about-document-types').textContent = docs.length;
  $('map-select').replaceChildren(...maps.map(map => {
    const option = node('option', '', map.name);
    option.value = map.id;
    return option;
  }));
  for (const doc of docs) {
    const button = node('button', 'type-row');
    button.dataset.doc = doc.id;
    button.style.setProperty('--type', doc.color);
    button.title = doc.name;
    button.setAttribute('aria-pressed', 'true');
    const image = node('img', 'type-icon'); image.src = doc.icon; image.alt = '';
    const check = node('span', 'type-check'); check.append(icon('check'));
    button.append(image, node('span', 'type-name', shortNames[doc.id] || doc.name), node('span', 'type-count'), check);
    button.addEventListener('click', () => {
      state.types.has(doc.id) ? state.types.delete(doc.id) : state.types.add(doc.id);
      render();
    });
    $('type-filters').append(button);
  }

  function buildFloors() {
    const documentLevels=documentFloors(mapSpawns()),values = documentOverlayFloors(mapSpawns(),mapById.get(state.mapId),overlay,pendingFloor);
    $('floor-group').hidden = !values.length || state.allMaps;
    const entries = [['all', 'Все этажи'], ...values.map(f => [f, (FLOOR_NAMES[f] || f)+(documentLevels.includes(f)?'':' · выходы')])];
    if (mapSpawns().some(s => !s.floor)) entries.push(['unknown', 'Этаж не указан']);
    $('floor-select').replaceChildren(...entries.map(([value, name]) => {
      const option = node('option', '', name); option.value = value; return option;
    }));
    if(!entries.some(([id])=>id===state.floor))state.floor=values[0]||'all';
    $('floor-select').value = state.floor;
    $('floor-group').querySelector('small').textContent=state.floor==='all'?'Все точки на основном плане. Для просмотра отдельного уровня выбери его в списке.':'Этаж здания уточняется в описании точки.';
  }

  function setMap(id, { updateUrl = true, restoreFloor = false } = {}) {
    const map = mapById.get(id);
    if (!map) return;
    closeDetails(false, false);
    state.mapId = id;pendingFloor=restoreFloor&&map.documentPanels?.[prefs.floor]?prefs.floor:null;
    state.floor = restoreFloor&&prefs.artworkVersion===2&&typeof prefs.floor==='string'?prefs.floor:map.documentPanels?'floor-1':'all';
    $('map-select').value = id;
    const raidAliases = { streets: 'streets-of-tarkov', groundzero: 'ground-zero', lab: 'the-lab', labyrinth: 'the-labyrinth' };
    $('raid-link').href = siteHref(`raid.html?map=${raidAliases[id] || id}`);
    $('location-thumb').src = map.layeredFile||map.file;
    $('location-thumb').hidden = false;
    $('map-index').textContent = `${String(maps.indexOf(map) + 1).padStart(2, '0')} / ${maps.length}`;
    const titleEn = node('span', '', 'МЕСТА ДОКУМЕНТОВ'); titleEn.id = 'title-en';
    $('map-title').replaceChildren(document.createTextNode(map.name), titleEn);
    document.title = `${map.name} — Escape from Tarkov Wiki`;
    const viewport=documentViewport(map);mapWidth = viewport.width; mapHeight = viewport.height;
    $('map-stage').style.width = mapWidth + 'px';
    $('map-stage').style.height = mapHeight + 'px';
    $('map-image').alt = 'Карта локации ' + map.name;
    $('map-loading').replaceChildren(node('span', 'spinner'), document.createTextNode('Загрузка карты'));
    $('map-loading').hidden = false;
    artworkKey = '';
    $('map-image').removeAttribute('src');
    $('minimap-image').removeAttribute('src');
    $('map-image').hidden = true;
    $('minimap').hidden = true;
    $('map-image').onload = () => { $('map-loading').hidden = true; $('map-image').hidden = false; $('minimap').hidden = false; };
    $('map-image').onerror = () => {
      $('map-loading').replaceChildren(document.createTextNode('Карта не загрузилась. Обновите страницу.'));
      $('map-loading').hidden = false;
    };
    if(!map.documentPanels)$('map-image').src = map.file;
    if(!map.documentPanels)$('minimap-image').src = map.file;
    $('alt-group').hidden = !mapSpawns().some(s => hasCoordinates(s, true));
    $('show-alts').checked = state.showAlts;
    buildFloors();
    fitMap();
    render();
    persistPrefs();
    if (updateUrl) setUrl();
    loadOverlay();
  }

  function render() {
    persistPrefs();
    updateMapArtwork();
    results = filterSpawns(spawns, { ...state, found: state.found });
    visible = results.filter(s => s.map === state.mapId && hasCoordinates(s));
    $('visible-count').textContent = visible.length;
    $('list-count').textContent = results.length;
    $('results-total').textContent = results.length;
    $('results-caption').textContent = state.allMaps ? 'Все локации' : 'Точки на локации';
    $('map-empty').hidden = visible.length > 0 || visibleDocumentExits(overlay,state.mapId,overlayMode,state.floor,showExits.checked).length>0;
    $('map-empty-text').textContent = state.allMaps && results.length
      ? `На других локациях найдено ${results.length} точек. Откройте вкладку «Точки» или смените карту.`
      : 'Попробуйте изменить запрос или фильтры.';
    const scopeSpawns = state.allMaps ? spawns : mapSpawns();
    for (const button of $('type-filters').children) {
      button.setAttribute('aria-pressed', String(state.types.has(button.dataset.doc)));
      button.querySelector('.type-count').textContent = scopeSpawns.filter(s => s.doc === button.dataset.doc).length;
    }
    for (const button of $('status-filters').children) {
      const active = button.dataset.status === state.status;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', String(active));
    }
    renderMarkers();
    renderList();
    renderProgress();
    updateDetailStatus();
    updatePhotoNavigation();
    if(state.selected)renderAccess(state.selected);
    renderRequiredKeys();
  }

  function renderRequiredKeys(){
    const groups=new Map();let locked=0;
    for(const point of results.filter(p=>p.map===state.mapId)){const access=overlay?.access?.[point.id];if(!access?.requiresKey)continue;locked++;
      for(const key of access.keys||[]){const id=key.id||key.name;if(!groups.has(id))groups.set(id,{...key,points:[]});groups.get(id).points.push(point);}}
    $('document-keys-status').textContent=overlay?(locked?'Документов за закрытыми дверями: '+locked+'. Список учитывает текущие фильтры.':'Для выбранных документов обязательные ключи не указаны.'):'Список ключей загружается…';
    $('document-keys-list').replaceChildren(...[...groups.values()].map(key=>{const li=node('li'),name=node(key.id?'a':'strong','document-key-card',key.name);
      if(key.iconLink){const img=image(key.iconLink,'','document-key-icon');img.width=48;img.height=48;name.prepend(img);}else{name.prepend(icon('lock'));}
      if(key.id)name.href=siteHref('reference.html?tab=items&item='+encodeURIComponent(key.id)+'&mode='+overlayMode);li.append(name);
      for(const point of key.points){const b=node('button','document-key-point',number(point)+'. '+point.caption);b.type='button';b.addEventListener('click',()=>{setSidebar(false);selectPoint(point);});li.append(b);}return li;}));
  }

  function renderList() {
    const fragment = document.createDocumentFragment();
    for (const spawn of results) {
      const doc = docById.get(spawn.doc);
      const button = node('button', 'point-card');
      button.style.setProperty('--type', doc.color);
      button.classList.toggle('found', state.found.has(spawn.id));
      button.classList.toggle('active', state.selected?.id === spawn.id);
      button.setAttribute('aria-label', `${number(spawn)}. ${spawn.caption}. ${doc.name}. ${mapById.get(spawn.map).name}${state.found.has(spawn.id) ? '. Найдено' : ''}`);
      const text = node('span', 'card-text');
      text.append(node('strong', '', spawn.caption || doc.name), node('small', '', doc.name));
      if (state.allMaps) text.append(node('span', 'card-map', mapById.get(spawn.map).name));
      const access=overlay?.access?.[spawn.id];if(access?.requiresKey){text.append(lockBadge(access));button.setAttribute('aria-label',button.getAttribute('aria-label')+'. '+accessLabel(access));}
      button.append(node('span', 'list-number', state.found.has(spawn.id) ? '✓' : number(spawn)), text);
      button.addEventListener('click', () => { setSidebar(false); selectPoint(spawn); });
      fragment.append(button);
    }
    if (!results.length) {
      const empty = node('div', 'list-empty');
      const reset = node('button', '', 'Сбросить фильтры'); reset.addEventListener('click', resetFilters);
      empty.append(icon('search'), node('span', '', 'По этим условиям ничего не найдено.'), reset);
      fragment.append(empty);
    }
    $('point-list').replaceChildren(fragment);
  }

  function renderMarkers() {
    const fragment = document.createDocumentFragment();
    markers = [];
    for (const spawn of visible) {
      const doc = docById.get(spawn.doc);
      const variants = state.showAlts && hasCoordinates(spawn, true) ? [false, true] : [false];
      for (const alternate of variants) {
        const button = node('button', 'marker' + (alternate ? ' alternate' : ''));
        const documentIcon = node('img', 'document-marker-icon'); documentIcon.src = doc.icon; documentIcon.alt = ''; documentIcon.draggable = false; button.append(documentIcon);
        button.style.setProperty('--type', doc.color);
        button.classList.toggle('found', state.found.has(spawn.id));
        button.classList.toggle('active', state.selected?.id === spawn.id);
        button.dataset.tip = `${doc.name} · ${spawn.caption}`;
        const access=overlay?.access?.[spawn.id];if(access?.requiresKey){button.append(lockBadge(access));button.dataset.tip+=' · '+accessLabel(access);}
        button.dataset.spawn = spawn.id;
        button.setAttribute('aria-label', `Точка ${number(spawn)}${alternate ? ', на схеме' : ''}: ${doc.name}. ${spawn.caption}${accessLabel(overlay?.access?.[spawn.id])?'. '+accessLabel(overlay.access[spawn.id]):''}`);
        button.addEventListener('click', e => { e.stopPropagation(); selectPoint(spawn, false); });
        button.addEventListener('pointerenter', () => drawLink(spawn));
        button.addEventListener('pointerleave', () => drawLink(state.selected));
        button.addEventListener('focus', () => drawLink(spawn));
        button.addEventListener('blur', () => drawLink(state.selected));
        fragment.append(button);
        markers.push({ element: button, spawn, ...documentPointPosition(spawn,mapById.get(spawn.map),alternate) });
      }
    }
    for(const exit of visibleDocumentExits(overlay,state.mapId,overlayMode,state.floor,showExits.checked)){
      const button=node('button','marker document-exit');button.type='button';button.dataset.tip='Выход ЧВК: '+exit.name;
      const symbol=node('span','document-exit-symbol','↗');symbol.setAttribute('aria-hidden','true');button.append(symbol);
      button.setAttribute('aria-label','Выход ЧВК: '+exit.name);button.addEventListener('click',event=>{event.stopPropagation();openExit(exit,button);});
      fragment.append(button);markers.push({element:button,spawn:exit,x:exit.x,y:exit.y});
    }
    $('marker-layer').replaceChildren(fragment);
    applyView();
  }

  function drawLink(spawn) {
    $('link-layer').replaceChildren();
    if (!spawn || !state.showAlts || !hasCoordinates(spawn, true) || !visible.some(s => s.id === spawn.id)) return;
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    const first=documentPointPosition(spawn,mapById.get(spawn.map)),second=documentPointPosition(spawn,mapById.get(spawn.map),true);
    for (const [key, value] of Object.entries({ x1: view.x + first.x / 100 * mapWidth * view.scale, y1: view.y + first.y / 100 * mapHeight * view.scale, x2: view.x + second.x / 100 * mapWidth * view.scale, y2: view.y + second.y / 100 * mapHeight * view.scale })) line.setAttribute(key, value);
    line.setAttribute('stroke', docById.get(spawn.doc).color);
    $('link-layer').append(line);
  }

  function renderProgress() {
    const current = mapSpawns();
    const done = current.filter(s => state.found.has(s.id)).length;
    const activeFound = spawns.filter(s => state.found.has(s.id)).length;
    $('total-found').textContent = activeFound;
    $('map-progress-text').textContent = `${done} из ${current.length} точек`;
    $('map-ring').style.setProperty('--progress', (done / current.length * 360) + 'deg');
    $('progress-summary-found').textContent = activeFound;
    $('progress-summary-total').textContent = `из ${spawns.length} возможных`;
    $('progress-percent').textContent = Math.round(activeFound / spawns.length * 100) + '%';
    $('progress-map-list').replaceChildren(...maps.map(map => {
      const total = spawns.filter(s => s.map === map.id);
      const count = total.filter(s => state.found.has(s.id)).length;
      const button = node('button', 'progress-map');
      const label = node('span', 'progress-map-label');
      label.append(node('span', '', map.name), node('small', '', `${count} / ${total.length}`));
      const track = node('div', 'progress-track');
      const bar = node('i'); bar.style.width = (count / total.length * 100) + '%'; track.append(bar);
      button.append(label, track);
      button.title = 'Открыть локацию ' + map.name;
      button.addEventListener('click', () => { $('progress-dialog').close(); resetFilters(false); setMap(map.id); setSidebar(false); });
      return button;
    }));
  }

  function selectPoint(spawn, center = true, focus = true) {
    pendingFloor=null;
    if (focus) lastFocus = document.activeElement;
    if (spawn.map !== state.mapId) setMap(spawn.map, { updateUrl: false });
    if(state.floor!=='all'&&spawn.floor&&state.floor!==spawn.floor){state.floor=spawn.floor;buildFloors();render();}
    state.selected = spawn;
    state.photo = 0;
    const doc = docById.get(spawn.doc);
    $('detail-panel').hidden = false;
    $('map-area').classList.add('detail-open');
    $('detail-number').textContent = `ТОЧКА ${number(spawn)} / ${mapById.get(spawn.map).en.toUpperCase()}`;
    $('detail-icon').src = doc.icon;
    $('detail-type-name').textContent = doc.name;
    $('detail-location').textContent = mapById.get(spawn.map).name;
    $('detail-floor').textContent = FLOOR_NAMES[spawn.floor] || '';
    $('detail-caption').textContent = spawn.caption || 'Описание места пока не добавлено.';
    $('point-note').value = state.notes[spawn.id] || '';
    $('note-state').textContent = '';
    setPhoto(0);
    render();
    setUrl();
    if (center) centerOn(spawn);
    if (focus) $('detail-close').focus({ preventScroll: true });
  }

  function updateDetailStatus() {
    const spawn = state.selected;
    if (!spawn) return;
    const found = state.found.has(spawn.id);
    $('found-button').classList.toggle('is-found', found);
    $('found-button').setAttribute('aria-pressed', String(found));
    $('found-button').querySelector('span').textContent = found ? 'Найдено · отменить отметку' : 'Отметить найденным';
    const index = results.findIndex(s => s.id === spawn.id);
    $('detail-position').textContent = index >= 0 ? `Точка ${index + 1} из ${results.length}` : 'Точка скрыта текущим фильтром';
    $('prev-point').disabled = results.length === 0;
    $('next-point').disabled = results.length === 0;
  }

  function closeDetails(updateUrl = true, restoreFocus = true) {
    state.selected = null;
    $('detail-panel').hidden = true;
    $('map-area').classList.remove('detail-open');
    if (updateUrl) setUrl();
    for (const marker of markers) marker.element.classList.remove('active');
    for (const card of $('point-list').children) card.classList.remove('active');
    drawLink(null);
    if (restoreFocus) (lastFocus?.isConnected ? lastFocus : $('viewport')).focus({ preventScroll: true });
  }

  function setPhoto(index) {
    const spawn = state.selected;
    if (!spawn) return;
    const count = spawn.images?.length || 0;
    state.photo = count ? (index + count) % count : 0;
    const path = count ? spawn.images[state.photo] : null;
    $('photo-error').hidden = true;
    $('detail-photo').onerror = () => { $('photo-error').hidden = false; };
    $('detail-photo').onload = () => { $('photo-error').hidden = true; };
    $('photo-open').disabled = !path;
    if (path) {
      $('detail-photo').src = path;
      $('detail-photo').alt = spawn.caption || docById.get(spawn.doc).name;
      $('lightbox-image').src = path;
      $('lightbox-image').alt = spawn.caption || docById.get(spawn.doc).name;
      $('original-photo').href = path;
    } else {
      $('detail-photo').removeAttribute('src');
      $('photo-error').textContent = 'Фотография пока не добавлена';
      $('photo-error').hidden = false;
    }
    $('lightbox-title').textContent = `Точка ${number(spawn)} · ${docById.get(spawn.doc).name} · ${mapById.get(spawn.map).name}`;
    $('lightbox-caption').textContent = spawn.caption;
    updatePhotoNavigation();
  }

  function gallerySlides() {
    // Keep a selected point accessible even after it is hidden by the progress filter.
    const ids = new Set(visible.map(s => s.id));
    if (state.selected) ids.add(state.selected.id);
    return mapSpawns().filter(s => ids.has(s.id)).flatMap(spawn =>
      (spawn.images || []).map((_, photo) => ({ spawn, photo })));
  }

  function updatePhotoNavigation() {
    if (!state.selected) return;
    const slides = gallerySlides();
    const index = slides.findIndex(s => s.spawn.id === state.selected.id && s.photo === state.photo);
    const count = slides.length;
    $('photo-navigation').hidden = count < 2;
    $('photo-count').textContent = `${index + 1} / ${count}`;
    $('lightbox-count').textContent = `Фото ${index + 1} из ${count} · по фильтрам этой локации`;
    for (const id of ['photo-prev', 'photo-next', 'lightbox-prev', 'lightbox-next']) $(id).disabled = count < 2;
  }

  function nextPhoto(direction) {
    const slides = gallerySlides();
    if (!slides.length) return;
    const index = slides.findIndex(s => s.spawn.id === state.selected?.id && s.photo === state.photo);
    const next = slides[(index + direction + slides.length) % slides.length];
    if (next.spawn.id !== state.selected?.id) selectPoint(next.spawn, false, false);
    setPhoto(next.photo);
  }

  function nextPoint(direction) {
    if (!results.length) return;
    let index = results.findIndex(s => s.id === state.selected?.id);
    if (index < 0) index = direction > 0 ? -1 : 0;
    const next = results[(index + direction + results.length) % results.length];
    selectPoint(next);
  }

  function resetFilters(shouldRender = true) {
    state.types = new Set(docs.map(d => d.id));
    state.query = ''; state.status = 'all'; state.floor = 'all'; state.allMaps = false;
    $('search').value = ''; $('all-maps').checked = false;
    buildFloors();
    if (shouldRender) render();
  }

  function fitMap() {
    const rect = $('viewport').getBoundingClientRect();
    view = fitDocumentOverlayView(rect.width, rect.height, mapWidth, mapHeight, compactViewport() ? 18 : 30);
    baseScale = view.scale;
    applyView();
  }
  function clampScale(scale) { return Math.min(baseScale * 14, Math.max(baseScale * .45, scale)); }
  function applyView() {
    const rect = $('viewport').getBoundingClientRect();
    view.x = Math.min(rect.width - 30, Math.max(30 - mapWidth * view.scale, view.x));
    view.y = Math.min(rect.height - 30, Math.max(30 - mapHeight * view.scale, view.y));
    $('map-stage').style.transform = `translate(${view.x}px, ${view.y}px) scale(${view.scale})`;
    for (const marker of markers) {
      marker.element.style.left = (view.x + marker.x / 100 * mapWidth * view.scale) + 'px';
      marker.element.style.top = (view.y + marker.y / 100 * mapHeight * view.scale) + 'px';
      if(marker.element.classList.contains('document-exit')){const x=view.x+marker.x/100*mapWidth*view.scale,y=view.y+marker.y/100*mapHeight*view.scale,tipWidth=Math.min(240,rect.width-16);
        marker.element.style.setProperty('--exit-tooltip-width',tipWidth+'px');marker.element.style.setProperty('--exit-tooltip-shift',Math.max(8+tipWidth/2-x,Math.min(rect.width-8-tipWidth/2-x,0))+'px');marker.element.classList.toggle('tip-below',y<100);
      }
    }
    $('zoom-level').textContent = Math.round(view.scale / baseScale * 100) + '%';
    drawLink(state.selected);
    updateMinimap();
  }
  function zoomAt(px, py, multiplier) {
    view = zoomTransform(view, px, py, clampScale(view.scale * multiplier));
    applyView();
  }
  function usableCenter() {
    const rect = $('viewport').getBoundingClientRect();
    let width = rect.width, height = rect.height;
    if (state.selected && !$('detail-panel').hidden) {
      const panel = $('detail-panel').getBoundingClientRect();
      if (compactViewport()) height = Math.max(70, panel.top - rect.top);
      else width = Math.max(75, panel.left - rect.left);
    }
    return { x: width / 2, y: height / 2 };
  }
  function centerOn(spawn) {
    if (!hasCoordinates(spawn)) return;
    const center = usableCenter();
    view.scale = clampScale(Math.max(view.scale, baseScale * 1.8));
    const point=documentPointPosition(spawn,mapById.get(spawn.map));
    view.x = center.x - point.x / 100 * mapWidth * view.scale;
    view.y = center.y - point.y / 100 * mapHeight * view.scale;
    applyView();
  }
  function minimapGeometry() {
    const rect = $('minimap').getBoundingClientRect();
    const scale = Math.min(rect.width / mapWidth, rect.height / mapHeight);
    return { rect, scale, x: (rect.width - mapWidth * scale) / 2, y: (rect.height - mapHeight * scale) / 2 };
  }
  function updateMinimap() {
    if (getComputedStyle($('minimap')).display === 'none') return;
    const { scale, x, y } = minimapGeometry();
    const rect = $('viewport').getBoundingClientRect();
    Object.assign($('minimap-view').style, {
      left: (x - view.x / view.scale * scale) + 'px', top: (y - view.y / view.scale * scale) + 'px',
      width: (rect.width / view.scale * scale) + 'px', height: (rect.height / view.scale * scale) + 'px',
    });
  }

  const pointers = new Map();
  $('viewport').addEventListener('pointerdown', event => {
    if (event.target.closest('button') || event.button > 0) return;
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    $('viewport').setPointerCapture(event.pointerId);
    $('viewport').classList.add('dragging');
  });
  $('viewport').addEventListener('pointermove', event => {
    if (!pointers.has(event.pointerId)) return;
    const previous = [...pointers.values()];
    const old = pointers.get(event.pointerId);
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    const next = [...pointers.values()];
    if (pointers.size === 1) {
      view.x += event.clientX - old.x; view.y += event.clientY - old.y;
    } else if (pointers.size === 2) {
      const oldDistance = Math.hypot(previous[1].x - previous[0].x, previous[1].y - previous[0].y);
      const newDistance = Math.hypot(next[1].x - next[0].x, next[1].y - next[0].y);
      const rect = $('viewport').getBoundingClientRect();
      const oldX = (previous[0].x + previous[1].x) / 2 - rect.left;
      const oldY = (previous[0].y + previous[1].y) / 2 - rect.top;
      const nextX = (next[0].x + next[1].x) / 2 - rect.left;
      const nextY = (next[0].y + next[1].y) / 2 - rect.top;
      const scale = clampScale(view.scale * (oldDistance > 0 ? newDistance / oldDistance : 1));
      const ratio = scale / view.scale;
      view = { scale, x: nextX - (oldX - view.x) * ratio, y: nextY - (oldY - view.y) * ratio };
    }
    applyView();
  });
  for (const type of ['pointerup', 'pointercancel', 'lostpointercapture']) $('viewport').addEventListener(type, event => {
    pointers.delete(event.pointerId);
    if (!pointers.size) $('viewport').classList.remove('dragging');
  });
  $('viewport').addEventListener('wheel', event => {
    event.preventDefault();
    const rect = $('viewport').getBoundingClientRect();
    zoomAt(event.clientX - rect.left, event.clientY - rect.top, Math.exp(-Math.max(-120, Math.min(120, event.deltaY)) * .0025));
  }, { passive: false });
  $('viewport').addEventListener('dblclick', event => {
    if (event.target.closest('button')) return;
    const rect = $('viewport').getBoundingClientRect();
    zoomAt(event.clientX - rect.left, event.clientY - rect.top, 1.6);
  });
  $('minimap').addEventListener('click', event => {
    const { rect, scale, x, y } = minimapGeometry();
    const px = Math.max(0, Math.min(mapWidth, (event.clientX - rect.left - x) / scale));
    const py = Math.max(0, Math.min(mapHeight, (event.clientY - rect.top - y) / scale));
    const center = usableCenter();
    view.x = center.x - px * view.scale; view.y = center.y - py * view.scale;
    applyView();
  });
  $('minimap').addEventListener('keydown', event => {
    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); fitMap(); }
  });
  const resize = new ResizeObserver(() => {
    updateSidebarAccess();
    fitMap();
    if (state.selected) centerOn(state.selected);
  });
  resize.observe($('viewport'));

  $('map-select').addEventListener('change', event => { setMap(event.target.value); setSidebar(false); });
  $('search').addEventListener('input', event => { state.query = event.target.value; if (state.query) chooseTab('points'); render(); });
  $('all-maps').addEventListener('change', event => { state.allMaps = event.target.checked; state.floor = 'all'; buildFloors(); chooseTab('points'); render(); });
  $('floor-select').addEventListener('change', event => { pendingFloor=null;state.floor = event.target.value; closeDetails(false,false); buildFloors();render(); });
  $('show-alts').addEventListener('change', event => { state.showAlts = event.target.checked; persistPrefs(); renderMarkers(); });
  $('document-icon-size').addEventListener('input', event => {
    state.iconSize = documentIconSize(event.target.valueAsNumber);
    applyIconSize(); persistPrefs();
  });
  $('all-types').addEventListener('click', () => { state.types = new Set(docs.map(d => d.id)); render(); });
  for (const button of $('status-filters').children) button.addEventListener('click', () => { state.status = button.dataset.status; render(); });
  for (const id of ['reset-filters', 'empty-reset']) $(id).addEventListener('click', () => resetFilters());
  $('filters-tab').addEventListener('click', () => chooseTab('filters'));
  $('points-tab').addEventListener('click', () => chooseTab('points'));
  for (const id of ['filters-tab', 'points-tab']) $(id).addEventListener('keydown', event => {
    if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
    event.preventDefault(); const tab = id === 'filters-tab' ? 'points' : 'filters'; chooseTab(tab); $(tab + '-tab').focus();
  });
  $('menu-button').addEventListener('click', () => setSidebar(!$('app-layout').classList.contains('sidebar-open')));
  $('sidebar-backdrop').addEventListener('click', () => setSidebar(false));
  $('zoom-in').addEventListener('click', () => { const c = usableCenter(); zoomAt(c.x, c.y, 1.35); });
  $('zoom-out').addEventListener('click', () => { const c = usableCenter(); zoomAt(c.x, c.y, 1 / 1.35); });
  $('fit-button').addEventListener('click', fitMap);
  $('fullscreen-button').addEventListener('click', async () => {
    try {
      if (document.fullscreenElement) await document.exitFullscreen();
      else if (document.documentElement.requestFullscreen) await document.documentElement.requestFullscreen();
      else toast('Этот браузер не поддерживает полноэкранный режим.');
    } catch { toast('Браузер не разрешил полноэкранный режим.'); }
  });
  $('detail-close').addEventListener('click', () => closeDetails());
  $('center-button').addEventListener('click', () => { if (state.selected) centerOn(state.selected); });
  $('found-button').addEventListener('click', () => {
    if (!state.selected) return;
    const id = state.selected.id;
    const wasFound = state.found.has(id);
    wasFound ? state.found.delete(id) : state.found.add(id);
    const saved = persist(); render();
    if (saved) toast(wasFound ? 'Отметка снята' : 'Точка отмечена как найденная');
  });
  $('point-note').addEventListener('input', event => {
    if (!state.selected) return;
    const value = event.target.value.slice(0, 2000);
    if (value.trim()) state.notes[state.selected.id] = value;
    else delete state.notes[state.selected.id];
    $('note-state').textContent = persist() ? 'Сохранено' : 'Не сохранено';
  });
  $('prev-point').addEventListener('click', () => nextPoint(-1));
  $('next-point').addEventListener('click', () => nextPoint(1));
  $('photo-prev').addEventListener('click', () => nextPhoto(-1));
  $('photo-next').addEventListener('click', () => nextPhoto(1));
  $('photo-open').addEventListener('click', () => $('lightbox').showModal());
  $('lightbox-close').addEventListener('click', () => $('lightbox').close());
  $('lightbox-prev').addEventListener('click', () => nextPhoto(-1));
  $('lightbox-next').addEventListener('click', () => nextPhoto(1));
  let photoTouch = null;
  $('lightbox-image').addEventListener('touchstart', event => {
    photoTouch = event.touches.length === 1 ? { x: event.touches[0].clientX, y: event.touches[0].clientY } : null;
  }, { passive: true });
  $('lightbox-image').addEventListener('touchend', event => {
    if (!photoTouch || !event.changedTouches.length) return;
    const dx = event.changedTouches[0].clientX - photoTouch.x;
    const dy = event.changedTouches[0].clientY - photoTouch.y;
    if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy) * 1.5) nextPhoto(dx < 0 ? 1 : -1);
    photoTouch = null;
  }, { passive: true });
  $('lightbox-image').addEventListener('touchcancel', () => { photoTouch = null; });
  $('copy-button').addEventListener('click', async () => {
    setUrl();
    try { await navigator.clipboard.writeText(location.href); toast('Ссылка на точку скопирована'); }
    catch { window.prompt('Скопируйте ссылку на эту точку:', location.href); }
  });

  for (const id of ['progress-button', 'sidebar-progress']) $(id).addEventListener('click', () => { renderProgress(); $('progress-dialog').showModal(); });
  for (const id of ['about-button', 'credits-button']) $(id).addEventListener('click', () => $('about-dialog').showModal());
  for (const dialog of document.querySelectorAll('dialog')) {
    dialog.querySelector('.dialog-close')?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
      if (event.target !== dialog) return;
      const rect = dialog.getBoundingClientRect();
      if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    });
  }
  const commitLink = node('span', '', source.commit.slice(0, 8));
  $('source-version').append(document.createTextNode(`Снимок данных: ${source.importedAt.split('-').reverse().join('.')} · Версия `), commitLink);

  $('export-button').addEventListener('click', () => {
    const backup = { version: 1, app: 'Escape from Tarkov Wiki', exportedAt: new Date().toISOString(), sourceCommit: source.commit, found: [...state.found], notes: state.notes };
    const blob = new Blob([JSON.stringify(backup, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = node('a'); link.href = url; link.download = `kord-atlas-progress-${new Date().toISOString().slice(0, 10)}.json`;
    document.body.append(link); link.click(); link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
  });
  $('import-button').addEventListener('click', () => $('import-file').click());
  $('import-file').addEventListener('change', async event => {
    const file = event.target.files[0];
    if (!file) return;
    try {
      if (file.size > 2 * 1024 * 1024) throw new Error('Файл слишком большой. Максимум — 2 МБ.');
      const imported = restoreProgress(JSON.parse(await file.text()));
      const before = state.found.size;
      for (const id of imported.found) state.found.add(id);
      state.notes = { ...state.notes, ...imported.notes };
      const saved = persist(); render();
      if (state.selected) $('point-note').value = state.notes[state.selected.id] || '';
      if (saved) toast(`Прогресс загружен. Новых отметок: ${state.found.size - before}.`);
    } catch (error) { toast(error instanceof SyntaxError ? 'Не удалось прочитать JSON. Выберите файл прогресса Escape from Tarkov Wiki.' : error.message); }
    event.target.value = '';
  });
  $('reset-progress').addEventListener('click', () => {
    if (!confirm('Удалить все найденные отметки и личные заметки? Перед сбросом можно скачать резервную копию.')) return;
    state.found.clear(); state.notes = {};
    const saved = persist(); render();
    if (state.selected) { $('point-note').value = ''; $('note-state').textContent = ''; }
    if (saved) toast('Прогресс сброшен');
  });
  addEventListener('wiki:state', event => {
    if (event.detail.key !== STORAGE_KEY) return;
    try {
      const next = restoreProgress(safeRead(STORAGE_KEY)||{found:[],notes:{}});
      state.found = new Set(next.found); state.notes = next.notes; render();
      if (state.selected && document.activeElement !== $('point-note')) $('point-note').value = state.notes[state.selected.id] || '';
    } catch { /* Ignore malformed writes from another tab. */ }
  });
  document.addEventListener('keydown', event => {
    if ($('lightbox').open) {
      if (event.key === 'ArrowLeft') { event.preventDefault(); nextPhoto(-1); }
      if (event.key === 'ArrowRight') { event.preventDefault(); nextPhoto(1); }
      return;
    }
    if (document.querySelector('dialog[open]')) return;
    if (event.key === 'Escape') {
      if ($('app-layout').classList.contains('sidebar-open')) setSidebar(false);
      else if (state.selected) closeDetails();
      return;
    }
    if (event.target.closest('input,textarea,select,[contenteditable=true]') || event.ctrlKey || event.metaKey || event.altKey) return;
    if (event.key === '/') { event.preventDefault(); if (compactViewport()) setSidebar(true); $('search').focus(); }
    else if (['+', '=', '-'].includes(event.key)) {
      event.preventDefault(); const c = usableCenter(); zoomAt(c.x, c.y, event.key === '-' ? 1 / 1.3 : 1.3);
    } else if (event.key === '0') { event.preventDefault(); fitMap(); }
    else if (event.target === $('viewport') && ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
      event.preventDefault();
      if (event.key === 'ArrowLeft') view.x += 55;
      if (event.key === 'ArrowRight') view.x -= 55;
      if (event.key === 'ArrowUp') view.y += 55;
      if (event.key === 'ArrowDown') view.y -= 55;
      applyView();
    }
  });
  $('search').value=state.query;$('all-maps').checked=state.allMaps;
  applyIconSize();
  chooseTab(state.tab);
  setMap(state.mapId, { updateUrl: !initialPoint,restoreFloor:!initialPoint&&(!params.has('map')||params.get('map')===prefs.map) });
  if (initialPoint) selectPoint(initialPoint);
  // Открытие карты и изменение размера значков не записывают игровой прогресс.
}

await start();
