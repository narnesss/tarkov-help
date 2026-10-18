import {siteHref,pageParams,canonicalURL,usesPageRoutes} from './site-routes.mjs?v=page-content-20261017';
import {resolveGameMode} from './game-mode.mjs?v=page-content-20261017';
import {ruText,localizeDisplay,currencyLabel,isVisibleItem} from './ru-data.mjs?v=page-content-20261017';
import {loadReferenceSources} from './reference-loader.mjs?v=page-content-20261017';
import {pageDataset} from './page-data.mjs?v=loading-20261018';
import {$,el,num,image,external,json,readStorage,writeStorage,toast,section,action,empty} from './guide-common.mjs?v=loading-20261018';
import {idOf,list,mapAlias,sanitizeQuestProgress,requiredQuestItems} from './quest-model.mjs?v=page-content-20261017';
import {normalize} from './model.mjs?v=page-content-20261017';
import {formatDuration,formatRaidRemaining} from './time-format.mjs?v=page-content-20261017';
import {bossSpawns} from './boss-model.mjs?v=page-content-20261017';
import {sellPrice} from './reference-model.mjs?v=page-content-20261017';
const TABS={items:['Предметы','Поиск предметов, предложения торговцев, габариты и ссылки на связанные квесты.'],ammo:['Патроны','Урон, пробитие, бронеурон и скорость. Значения из свойств патронов; это не расчёт вероятности пробития.'],needed:['Для квестов','Предметы для ещё не выполненных заданий. Отметки берутся из вашего журнала квестов.'],barters:['Бартеры','Что отдать торговцу и что получить. Требуемые предметы, количество и условия открытия.'],crafts:['Крафты','Рецепты убежища, требуемые предметы, инструменты и время производства.'],hideout:['Убежище','Требования к постройкам и их уровням, предметы и личные отметки.'],bosses:['Боссы','Описание и здоровье боссов. Шанс спавна и зоны появления — на отдельной странице каждого босса.'],traders:['Торговцы','Уровни лояльности, репутация, торговый оборот и переход к квестам.'],achievements:['Достижения','Условия получения, редкость и личные отметки.'],btr:['Маршруты БТР','Справочные маршруты NepPure. Время — остаток рейда, погрешность источника ±60 секунд.']};
const TYPE_NAMES={quest:'Квестовые предметы',gun:'Оружие',ammo:'Боеприпасы',armor:'Броня',rig:'Разгрузки',backpack:'Рюкзаки',headphones:'Наушники',helmet:'Шлемы',medical:'Медицина',provisions:'Еда и напитки',keys:'Ключи',key:'Ключи',mods:'Модификации',barter:'Бартерные предметы',container:'Контейнеры',wearable:'Снаряжение',preset:'Сборки',special:'Специальные',noFlea:'Нет на барахолке'};
const params=pageParams();let mode=resolveGameMode(params.get('mode'),readStorage('kord-atlas-game-mode')),tab=TABS[params.get('tab')]?params.get('tab'):'items',page=0,data,catalog,quests,btr,bosses,items=new Map(),traders=new Map(),stations=new Map(),token=0;
const viewKey=()=>`kord-atlas-reference-view-${mode}-v1`;
function saveView(){if(!$('reference-search'))return;const old=readStorage(viewKey())||{};writeStorage(viewKey(),{...old,tab,views:{...old.views,[tab]:{query:$('reference-search').value,filter:$('reference-filter').value,sort:$('reference-sort').value,page}}});}
function restoreView(){if(!$('reference-search'))return;const view=readStorage(viewKey())?.views?.[tab]||{};$('reference-search').value=typeof view.query==='string'?view.query:'';for(const key of ['filter','sort']){const n=$('reference-'+key);if([...n.options].some(o=>o.value===view[key]))n.value=view[key];}page=Number.isInteger(view.page)&&view.page>=0?view.page:0;}
const savedView=readStorage(viewKey());if(!params.has('tab')&&TABS[savedView?.tab])tab=savedView.tab;
const pageSize=40;const money=v=>v===null||v===undefined?'Нет цены':num(v)+' ₽';const itemName=id=>items.get(idOf(id))?.name||'Предмет '+idOf(id);const nameTrader=id=>traders.get(idOf(id))?.name||'Торговец';
const duration=formatDuration;
function itemLink(ref,count,linked=true){const item=items.get(idOf(ref));const b=!linked?el('span','','guide-item'):usesPageRoutes()?el('a','','guide-item'):action('',()=>showItem(idOf(ref)),'guide-item');if(linked&&usesPageRoutes())b.href=siteHref('reference.html?'+new URLSearchParams({tab:'items',item:idOf(ref),mode}));if(item?.iconLink)b.append(image(item.iconLink));b.append(el('span',item?.name||'Предмет '+idOf(ref)));if(count!==undefined)b.append(el('b','× '+num(count)));return b;}
function setOptions(id,choices){$(id).replaceChildren(...choices.map(([value,name])=>{const o=el('option',name);o.value=value;return o;}));}
function revealActiveTab(){const nav=$('reference-tabs');if(!nav)return;const active=nav.querySelector('[aria-current="page"]');if(!active||getComputedStyle(nav).flexDirection!=='row'||nav.scrollWidth<=nav.clientWidth)return;const box=nav.getBoundingClientRect(),target=active.getBoundingClientRect();nav.scrollTo({left:nav.scrollLeft+target.left-box.left-(nav.clientWidth-target.width)/2,behavior:'auto'});}
function setTab(next){if(!$('reference-search')){location.assign(siteHref('reference.html?'+new URLSearchParams({tab:next,mode})));return;}if(!TABS[next])return;tab=next;page=0;$('reference-search').value='';const u=new URL(usesPageRoutes()?'reference.html':location.href,location.origin+'/');u.search='';u.searchParams.set('tab',tab);u.searchParams.set('mode',mode);history.replaceState(null,'',canonicalURL(u));return load().catch(fail);}
function configure(){if(!$('reference-search')){$('reference-mode').value=mode;return;}
  $('reference-title').textContent=TABS[tab][0];$('reference-crumb').textContent=TABS[tab][0];$('reference-description').textContent=TABS[tab][1];$('reference-mode').value=mode;
  $('reference-tabs').replaceChildren(...Object.entries(TABS).map(([id,[name]])=>{const b=action(name,()=>setTab(id),'');b.dataset.tab=id;if(tab===id)b.setAttribute('aria-current','page');return b;}));
  revealActiveTab();
  let filters=[['all','Все категории']],sorts=[['name','По названию']];
  if(tab==='items')filters.push(...[...new Set([...items.values()].flatMap(i=>i.types||[]))].sort().map(t=>[t,TYPE_NAMES[t]||t]));
  if(tab==='ammo')filters=[['all','Все калибры'],...[...new Set([...items.values()].filter(i=>i.properties?.propertiesType==='ItemPropertiesAmmo').map(i=>i.properties.caliber))].filter(Boolean).sort().map(c=>[c,c.replace(/^Caliber/,'')])];
  if(tab==='barters')filters=[['all','Все торговцы'],...list(data.traders).map(t=>[t.id,t.name])];
  if(tab==='crafts')filters=[['all','Все станции'],...list(data.hideout).map(s=>[s.id,s.name])];
  if(tab==='hideout')filters=[['all','Все модули'],...list(data.hideout).map(s=>[s.id,s.name]).sort((a,b)=>a[1].localeCompare(b[1],'ru'))];
  if(tab==='bosses')filters=[['all','Все локации'],...list(quests.maps).filter(m=>!['night-factory','ground-zero-21','ground-zero-tutorial','the-lab-dark'].includes(m.normalizedName)).map(m=>[m.normalizedName,m.name])];
  if(tab==='btr')filters=[['all','Все локации'],...Object.values(btr.maps).filter(m=>list(m.routes).length).map(m=>[m.mapId,list(quests.maps).find(x=>x.normalizedName===m.mapId)?.name||m.mapId]).sort((a,b)=>a[1].localeCompare(b[1],'ru'))];
  if(tab==='needed')filters=[['all','Все нужные предметы'],['fir','Найдено в рейде']];
  if(tab==='ammo')sorts.push(['penetration','По пробитию ↓'],['damage','По урону ↓']);
  const filterTitle=['hideout','crafts'].includes(tab)?'Модуль':tab==='barters'?'Торговец':tab==='ammo'?'Калибр':tab==='btr'?'Локация':'Категория';$('reference-filter-title').textContent=filterTitle;$('reference-filter').setAttribute('aria-label',filterTitle);
  setOptions('reference-filter',filters);setOptions('reference-sort',sorts);$('reference-filter').hidden=filters.length===1;$('reference-sort').hidden=sorts.length===1;$('reference-filter-label').hidden=filters.length===1;$('reference-sort-label').hidden=sorts.length===1;
}
function sourceRows(){
  if(tab==='items')return [...items.values()].filter(isVisibleItem);if(tab==='ammo')return [...items.values()].filter(i=>i.properties?.propertiesType==='ItemPropertiesAmmo');
  if(tab==='needed'){const p=sanitizeQuestProgress(readStorage(`kord-atlas-quests-${mode}-v1`),quests.tasks);return requiredQuestItems(quests.tasks.filter(t=>t.catalogSection!=='supplementary'),p).map(r=>({...r,name:itemName(r.id)}));}
  if(tab==='bosses')return bosses?.profiles||[];
  if(tab==='btr')return Object.values(btr.maps).flatMap(m=>(m.routes||[]).map(r=>({...r,map:m,name:`${quests.maps.find(x=>x.normalizedName===m.mapId)?.name||m.mapId} · ${r.label||r.id}`})));
  return list(data[tab]||[]).map(r=>({...r,name:r.name||itemName((r.productItem||r.offeredItem)?.item)}));
}
function filtered(){const words=normalize($('reference-search').value).split(/\s+/).filter(Boolean),filter=$('reference-filter').value,sort=$('reference-sort').value;
  const rows=sourceRows().filter(r=>{
    if(filter!=='all'){
      if(tab==='items'&&!r.types?.includes(filter))return false;if(tab==='ammo'&&r.properties.caliber!==filter)return false;
      if(tab==='barters'&&idOf(r.trader)!==filter)return false;if(tab==='crafts'&&idOf(r.station)!==filter)return false;
      if(tab==='hideout'&&r.id!==filter)return false;
      if(tab==='bosses'&&!bossSpawns(r,data).some(s=>s.map.normalizedName===filter)&&!r.canonicalMapSlugs?.includes(filter))return false;if(tab==='btr'&&r.map.mapId!==filter)return false;
      if(tab==='needed'&&filter==='fir'&&!r.foundInRaid)return false;
    }
    const text=normalize([r.name,r.shortName,r.description,...[...(r.requiredItems||[]),...(r.requiredQuestItems||[])].map(x=>itemName(x.item)),...(r.tasks||[]).map(x=>x.name)].join(' '));return words.every(w=>text.includes(w));
  });
  rows.sort((a,b)=>sort==='damage'?(b.properties.damage||0)-(a.properties.damage||0):sort==='penetration'?(b.properties.penetrationPower||0)-(a.properties.penetrationPower||0):a.name.localeCompare(b.name,'ru'));return rows;
}
function render(){if(!data)return;$('reference-count').hidden=false;document.querySelector('.reference-pager').hidden=false;document.querySelector('.reference-search-label').hidden=false;const rows=filtered();page=Math.min(page,Math.max(0,Math.ceil(rows.length/pageSize)-1));saveView();const visible=rows.slice(page*pageSize,(page+1)*pageSize);$('reference-count').textContent=num(rows.length);$('reference-page').textContent=rows.length?`${page*pageSize+1}–${Math.min((page+1)*pageSize,rows.length)} из ${num(rows.length)}`:'Нет результатов';$('reference-prev').disabled=page===0;$('reference-next').disabled=(page+1)*pageSize>=rows.length;
  $('reference-data-date').textContent=tab==='btr'?`Снимок маршрутов: ${btr.meta.snapshotDate}, PVE. Справочное расписание, не данные текущего рейда.`:`Данные: ${new Date((['items','ammo'].includes(tab)?catalog:data).meta.fetchedAt).toLocaleString('ru-RU')} · ${mode==='pve'?'PVE':'PVP'}.`;
  const root=$('reference-content');root.replaceChildren();if(!visible.length){root.append(empty('Ничего не найдено. Измените поиск или категорию.'));return;}
  if(tab==='bosses'){const a=el('a','Все боссы: описание и здоровье →','button');a.href=siteHref(`boss.html?mode=${mode}`);a.classList.add('boss-catalog-link');root.append(a);}
  if(['items','ammo','needed'].includes(tab))renderTable(root,visible);else{const cards=el('div','','reference-cards');for(const row of visible)cards.append(renderCard(row));root.append(cards);}
}
function renderTable(root,rows){
  const wrap=el('div','','reference-table-wrap'),table=el('table','','reference-table reference-responsive-table'),head=el('thead'),tr=el('tr');
  const headers=tab==='ammo'?['Патрон','Калибр','Урон','Пробитие','Бронеурон','Скорость']:tab==='needed'?['Предмет','Нужно','Условие','Для заданий']:['Предмет','Торговцу','Габариты'];
  table.classList.add(tab==='ammo'?'reference-ammo-table':'reference-item-table');
  table.setAttribute('role','table');head.setAttribute('role','rowgroup');tr.setAttribute('role','row');
  for(const [index,h] of headers.entries()){const th=el('th',h,index>0&&!(tab==='needed'&&index===3)?'number':'');th.scope='col';th.setAttribute('role','columnheader');tr.append(th);}
  head.append(tr);table.append(head);const body=el('tbody');body.setAttribute('role','rowgroup');
  for(const r of rows){
    const row=el('tr'),name=el('td');row.setAttribute('role','row');name.append(itemLink(r.id));row.append(name);
    let values;if(tab==='ammo')values=[r.properties.caliber?.replace(/^Caliber/,''),r.properties.damage,r.properties.penetrationPower,num(r.properties.armorDamage)+'%',num(r.properties.initialSpeed)+' м/с'];else if(tab==='needed')values=[num(r.count),r.foundInRaid?'В рейде':'Любые'];else values=[money(sellPrice(r)),r.width&&r.height?`${r.width} × ${r.height}`:'—'];
    for(const v of values)row.append(el('td',String(v??'—'),'number'));
    if(tab==='needed'){const td=el('td');for(const t of r.tasks){const a=el('a',t.name,'guide-link');a.href=siteHref(`quests.html?quest=${encodeURIComponent(t.id)}&mode=${mode}`);td.append(a,el('br'));}row.append(td);}
    for(const [index,cell] of [...row.children].entries()){cell.setAttribute('role','cell');const label=el('span',headers[index],'reference-cell-label');label.setAttribute('aria-hidden','true');cell.prepend(label);}
    body.append(row);
  }
  table.append(body);wrap.append(table);root.append(wrap);
}
function renderCard(r){const card=el('article','','reference-card');card.append(el('h3',r.name));
  if(['hideout','crafts','barters'].includes(tab))card.classList.add('reference-workshop-card');
  if(['barters','crafts'].includes(tab)){
    const offered=r.productItem||r.offeredItem;card.append(itemLink(offered.item,offered.count));
    const station=stations.get(idOf(r.station)),context=el('p','','recipe-context');
    if(tab==='crafts'&&station?.imageLink)context.append(image(station.imageLink,'','recipe-station-icon'));
    context.append(el('span',tab==='barters'?`${nameTrader(r.trader)} · лояльность ${r.minTraderLevel}`:`${station?.name||'Убежище'} · уровень ${r.level} · ${duration(r.duration)}`));card.append(context,el('h4','Требуется'));
    for(const required of r.requiredItems||[]){card.append(itemLink(required.item,required.count));if(required.attributes?.tool)card.append(el('small','Инструмент: не расходуется'));}
    if(r.requiredQuestItems?.length){const materials=el('div','','recipe-quest-materials');materials.append(el('h4','Квестовые материалы'));for(const required of r.requiredQuestItems)materials.append(items.has(idOf(required.item))?itemLink(required.item,required.count,false):el('p','Название и изображение квестового материала пока отсутствуют в источнике.','recipe-material-unavailable'));card.append(materials);}
    if(r.taskUnlock){const t=quests.tasks.find(t=>t.id===idOf(r.taskUnlock));if(t){const a=el('a','Открывает: '+t.name,'guide-link');a.href=siteHref(`quests.html?quest=${t.id}&mode=${mode}`);card.append(el('p'),a);}}
  }else if(tab==='hideout'){
    const header=el('header','','hideout-card-header');if(r.imageLink)header.append(image(r.imageLink,'','hideout-module-icon'));header.append(card.querySelector('h3'));card.prepend(header);
    const built=readStorage('kord-hideout-'+mode)||{};
    for(const level of r.levels||[]){
      const d=el('details','','hideout-level'),summary=el('summary');
      summary.append(el('span',`Уровень ${level.level}`,'hideout-level-title'),' ',el('span',duration(level.constructionTime),'hideout-level-time'));d.append(summary);
      if(level.description)d.append(el('p',level.description));
      for(const v of level.itemRequirements||[]){d.append(itemLink(v.item,v.count));if(v.attributes?.foundInRaid)d.append(el('small','Нужно найти в рейде'));}
      for(const req of level.stationLevelRequirements||[])d.append(el('p',`${stations.get(idOf(req.station))?.name||'Постройка'}: уровень ${req.level}`));
      for(const req of level.traderRequirements||[])d.append(el('p',`${nameTrader(req.trader)}: уровень лояльности ${req.level||req.value}`));
      for(const req of level.skillRequirements||[])d.append(el('p',`${req.skill}: уровень ${req.level}`));
      for(const bonus of level.bonuses||[])d.append(el('p',`${bonus.name}${bonus.skill?' · '+bonus.skill:''}: ${num(bonus.value)}`));
      const done=action(built[level.id]?'✓ Построено':'Отметить построенным',()=>{const current=readStorage('kord-hideout-'+mode)||{};current[level.id]=!current[level.id];writeStorage('kord-hideout-'+mode,current);done.textContent=current[level.id]?'✓ Построено':'Отметить построенным';done.setAttribute('aria-pressed',String(current[level.id]));});
      done.setAttribute('aria-pressed',String(!!built[level.id]));d.append(done);card.append(d);
    }
  }else if(tab==='bosses'){
    card.classList.add('reference-boss-card');if(r.imagePortraitLink)card.prepend(image(r.imagePortraitLink,r.name,'reference-boss-portrait'));
    card.append(el('p',r.description),el('strong',`Общее здоровье: ${num(r.healthTotal)}`,'reference-boss-health'));
    const a=el('a','Описание и здоровье по частям тела →','button boss-detail-link');a.href=siteHref(`boss.html?boss=${encodeURIComponent(r.slug)}&mode=${mode}`);card.append(a);
  }else if(tab==='traders'){
    card.classList.add('reference-trader-card');
    const header=el('header','','trader-card-header');if(r.imageLink)header.append(image(r.imageLink,'','trader-portrait'));header.append(card.querySelector('h3'));card.prepend(header);
    if(r.description)card.append(el('p',r.description));
    if(r.levels?.length){
      card.append(el('h4','Уровни лояльности','trader-levels-title'));
      const levels=el('div','','trader-levels');
      for(const level of r.levels){
        const group=el('section','','trader-loyalty-level');group.append(el('h5',`Уровень ${level.level}`));
        const requirements=el('dl','','trader-level-requirements');
        for(const [label,value] of [['Уровень игрока',String(level.requiredPlayerLevel)],['Репутация',num(level.requiredReputation)],['Торговый оборот',`${num(level.requiredCommerce)} ${currencyLabel(r.currency||'')}`]]){
          const pair=el('div');pair.append(el('dt',label),el('dd',value));requirements.append(pair);
        }
        group.append(requirements);levels.append(group);
      }
      card.append(levels);
    }
    const a=el('a','Квесты торговца','button');a.href=siteHref(`quests.html?trader=${r.id}&mode=${mode}`);card.append(a);
  }else if(tab==='achievements'){
    if(r.imageLink)card.prepend(image(r.imageLink,'','achievement-icon'));card.append(el('p',r.description));card.append(el('p',`Получили: ${num(r.playersCompletedPercent)}% игроков · ${r.hidden?'Скрытое':'Обычное'} достижение`));const key='kord-achievements-'+mode,state=readStorage(key)||[];const b=action(state.includes(r.id)?'✓ Получено':'Отметить полученным',()=>{let current=readStorage(key)||[];current=current.includes(r.id)?current.filter(x=>x!==r.id):[...current,r.id];writeStorage(key,current);b.textContent=current.includes(r.id)?'✓ Получено':'Отметить полученным';b.setAttribute('aria-pressed',String(current.includes(r.id)));});b.setAttribute('aria-pressed',String(state.includes(r.id)));card.append(b);
  }else if(tab==='btr'){
    card.classList.add('reference-btr-card');
    const departure=el('p','','btr-departure');departure.append(el('span','Выезд:','btr-time-label'),' ',el('strong',formatRaidRemaining(r.spawnRemainingSeconds)));card.append(departure,el('p','Это расписание из снимка, а не отслеживание БТР.','btr-note'));
    const stops=el('ol','','btr-stop-list');stops.setAttribute('aria-label','Остановки маршрута');
    for(const visit of r.visits||[]){
      const stop=r.map.stops.find(x=>x.id===(visit.stopId||visit.id)),row=el('li');
      row.append(el('strong',stop?.name||visit.stopId||visit.name||'Остановка','btr-stop-name'),' ',el('span',formatRaidRemaining(visit.arrivalRemainingSeconds??visit.remainingSeconds),'btr-stop-time'));stops.append(row);
    }
    card.append(stops);const a=el('a','Открыть маршрут на карте','button');a.href=siteHref(`raid.html?map=${r.map.mapId}&route=${encodeURIComponent(r.id)}&artwork=source&mode=${mode}`);card.append(a);if(r.map.mapId==='lighthouse')card.append(el('p','Остановки Маяка есть в справочном снимке NepPure, но не подтверждены текущим API.','btr-note'));
  }return card;
}
async function showItem(id){let item=items.get(id);if(!item){if(pageParams().has('item'))throw Error('Предмет недоступен или снят с публикации.');toast('Карточка предмета недоступна.');return;}const inline=usesPageRoutes()&&pageParams().get('item')===id;const root=inline?$('reference-content'):$('item-body');
 if(catalog.catalog&&catalog.items.some(row=>row.id===id)&&!itemDetails.has(mode+':'+id)){
  const current=token,target=mode;root.replaceChildren(empty('Загружаем карточку предмета…'));if(!inline&&!$('item-dialog').open)$('item-dialog').showModal();
  try{const detail=await pageDataset('item-'+target+'-'+id,`data/guide/items-${target}.json`,{view:'detail',id});if(current!==token||target!==mode||(!inline&&!$('item-dialog').open))return;if(detail.related){data=localizeDisplay(detail.related.reference);quests=localizeDisplay(detail.related.quests);traders=new Map(list(data.traders).map(row=>[row.id,row]));}item=detail.items.find(row=>row.id===id);if(!item)throw Error('Предмет недоступен.');items.set(id,item);itemDetails.add(target+':'+id);}
  catch(error){if(current===token)root.replaceChildren(empty(error.message),action('Повторить',()=>showItem(id)));return;}
 }
 $('item-title').textContent=item.name;root.replaceChildren();const top=el('div','','item-detail-grid');if(item.image512pxLink||item.iconLink){const picture=image(item.image512pxLink||item.iconLink);picture.decoding='async';if(inline){picture.loading='eager';picture.fetchPriority='high';}top.append(picture);}const stats=el('div','','item-stat-grid');for(const [label,value] of [['Масса',item.weight!=null?num(item.weight)+' кг':'—'],['Размер',item.width&&item.height?`${item.width} × ${item.height}`:'—']]){const box=el('div',label);box.append(el('strong',value));stats.append(box);}top.append(stats);root.append(top);if(item.description)root.append(el('p',item.description));
  const prices=section('Предложения торговцев');for(const offer of item.buyFromTrader||item.buyFor||[])prices.append(el('p',`Купить: ${nameTrader(offer.vendor||offer.trader)} · ${num(offer.price)} ${currencyLabel(offer.currency||'RUB')}${offer.minTraderLevel?' · LL '+offer.minTraderLevel:''}`));for(const offer of item.sellToTrader||item.sellFor||[])prices.append(el('p',`Продать: ${nameTrader(offer.vendor||offer.trader)} · ${num(offer.price)} ${currencyLabel(offer.currency||'RUB')}`));if(!prices.querySelector('p'))prices.append(el('p','Предложений в снимке нет.'));root.append(prices);
  const use=section('Связанные квесты');const matched=quests.tasks.filter(t=>(t.objectives||[]).some(o=>(o.items||[o.item||o.questItem]).map(idOf).includes(id)));for(const t of matched){const a=el('a',t.name,'quest-link');a.href=siteHref(`quests.html?quest=${t.id}&mode=${mode}`);use.append(a);}if(!matched.length)use.append(el('p','Прямая связь с предметными целями в API не указана.'));root.append(use);const recipes=section('Рецепты и бартеры');for(const [key,label] of [['crafts','Крафты'],['barters','Бартеры']]){const count=list(data[key]).filter(r=>[idOf((r.productItem||r.offeredItem)?.item),...(r.requiredItems||[]).map(x=>idOf(x.item))].includes(id)).length;if(count){recipes.append(action(`${label}: ${count}`,async()=>{$('item-dialog').close();await setTab(key);$('reference-search').value=item.name;render();}));}}if(recipes.querySelector('button'))root.append(recipes);
  const links=el('div','','guide-button-row');if(item.wikiLink)links.append(external('Wiki ↗',item.wikiLink));root.append(links);if(inline){document.title=item.name+' — Escape from Tarkov Wiki';$('reference-title').textContent=item.name;$('reference-crumb').textContent=item.name;$('reference-description').textContent=item.shortName||'';$('reference-count').hidden=true;if(document.querySelector('.reference-pager'))document.querySelector('.reference-pager').hidden=true;if(document.querySelector('.reference-search-label'))document.querySelector('.reference-search-label').hidden=true;if($('reference-filter-label'))$('reference-filter-label').hidden=true;if($('reference-sort-label'))$('reference-sort-label').hidden=true;const back=el('a','← Все предметы','button');back.href=siteHref('reference.html?'+new URLSearchParams({tab:'items',mode}));root.prepend(back);}else if(!$('item-dialog').open)$('item-dialog').showModal();
}
const sourceRequests=new Map(),itemDetails=new Set();
function once(key,read){if(!sourceRequests.has(key))sourceRequests.set(key,read().catch(error=>{sourceRequests.delete(key);throw error;}));return sourceRequests.get(key);}
async function load(){const current=++token;if(pageParams().has('item')){$('reference-title').textContent='Загрузка предмета…';$('reference-description').textContent='';} $('reference-content').replaceChildren(empty('Загрузка справочника…'));const result=await loadReferenceSources(mode,{dataset:(key,path,options)=>once(key+JSON.stringify(options??{}),()=>pageDataset(key,path,options)),json:path=>once(path,()=>json(path))},tab,pageParams().get('item'));if(current!==token)return;[data,catalog,quests,btr,bosses]=result.slice(0,5).map(x=>localizeDisplay(x));if(!quests.cms)throw Error('Сервер вернул неполные данные.');items=new Map([...list(catalog.items),...list(quests.questItems).map(i=>({...i,types:['quest']}))].map(i=>[i.id,i]));itemDetails.clear();traders=new Map(list(data.traders).map(t=>[t.id,t]));stations=new Map(list(data.hideout).map(s=>[s.id,s]));configure();restoreView();if(pageParams().has('item'))await showItem(pageParams().get('item'));else render();}
window.addEventListener('resize',revealActiveTab);
$('reference-mode').onchange=()=>{mode=$('reference-mode').value;writeStorage('kord-atlas-game-mode',mode);page=0;const url=new URL(location.href);url.searchParams.set('mode',mode);history.replaceState(null,'',canonicalURL(url));return load().catch(fail);};if($('reference-search'))$('reference-search').oninput=()=>{page=0;render();};for(const id of ['reference-filter','reference-sort'])if($(id))$(id).onchange=()=>{page=0;render();};if($('reference-prev'))$('reference-prev').onclick=()=>{page--;render();};if($('reference-next'))$('reference-next').onclick=()=>{page++;render();};$('item-close').onclick=()=>$('item-dialog').close();function fail(){$('reference-content').replaceChildren(empty('Не удалось загрузить справочник.'),action('Повторить',()=>load().catch(fail)));}await load();
