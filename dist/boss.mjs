import {siteHref,pageParams,canonicalURL} from './site-routes.mjs';
import {resolveGameMode} from './game-mode.mjs';
import {publicAttribution} from './public-links.mjs';
import {$,action,el,empty,external,image,json,freshDataset,num,readStorage,writeStorage,section} from './guide-common.mjs';
import {localizeDisplay} from './ru-data.mjs';
import {mapAlias} from './quest-model.mjs';
import {normalize} from './model.mjs';
import {findBoss,bossSpawns,bossHealth,relatedBosses} from './boss-model.mjs';

const params=pageParams();
let mode=resolveGameMode(params.get('mode'),readStorage('kord-atlas-game-mode'));
let catalog,reference,request=0;
const prefsKey=()=>`kord-atlas-reference-view-${mode}-v1`;
const href=profile=>siteHref(`boss.html?boss=${encodeURIComponent(profile.slug)}&mode=${mode}`);
const svg=(name,attrs={})=>{const node=document.createElementNS('http://www.w3.org/2000/svg',name);for(const [key,value] of Object.entries(attrs))node.setAttribute(key,value);return node;};

function healthPanel(profile){
 const root=section('Здоровье по частям тела');root.classList.add('boss-health');const health=bossHealth(profile);
 if(!health.valid){root.append(empty('Показатели здоровья в источнике неполны.'));return root;}
 const figure=svg('svg',{viewBox:'0 0 300 425',role:'img','aria-label':`Здоровье ${profile.name}: ${health.total}. Разбивка по частям тела приведена в таблице.`});figure.classList.add('boss-body');
 const shapes={Head:'M125 42a25 25 0 1 0 50 0a25 25 0 1 0-50 0',Chest:'M117 79L183 79L194 155L169 172L131 172L106 155Z',Stomach:'M130 177L170 177L178 228L122 228Z',LeftArm:'M190 84L210 94L234 221L215 225L184 160Z',RightArm:'M110 84L90 94L66 221L85 225L116 160Z',LeftLeg:'M155 234L178 232L192 380L169 383L153 302Z',RightLeg:'M145 234L122 232L108 380L131 383L147 302Z'};
 for(const part of health.parts){const path=svg('path',{d:shapes[part.id],'data-part':part.id});const title=svg('title');title.textContent=`${part.bodyPart}: ${part.max}`;path.append(title);figure.append(path);}
 const total=svg('text',{x:'150',y:'417','text-anchor':'middle'});total.textContent=num(health.total);figure.append(total);
 const table=el('table','','boss-health-table'),head=el('thead'),headRow=el('tr');for(const text of ['Часть тела','Здоровье']){const th=el('th',text);th.scope='col';headRow.append(th);}head.append(headRow);table.append(head);
 const body=el('tbody');for(const part of health.parts){const row=el('tr');row.dataset.part=part.id;const label=el('th',part.bodyPart);label.scope='row';row.append(label,el('td',num(part.max)));body.append(row);}table.append(body);
 const foot=el('tfoot'),totalRow=el('tr');totalRow.append(el('th','Всего'),el('td',num(health.total)));foot.append(totalRow);table.append(foot);
 const grid=el('div','','boss-health-grid');grid.append(figure,table);root.append(grid);
 const source=profile.healthSource==='wiki'?'Источник: Wiki; показатели совпадают с сохранёнными снимками API PVP и PVE.':'Отдельный вариант Лаборатории не подтверждён страницей Wiki.';
 root.append(el('p',source,'boss-source-note'));if(profile.wikiUrl)root.append(external('Страница Wiki ↗',profile.wikiUrl));return root;
}
function mapLink(map,label){const link=el('a',label||map.name,'button');link.href=siteHref(`raid.html?map=${encodeURIComponent(mapAlias(map.normalizedName))}&mode=${mode}`);return link;}
function detail(profile){
 const root=$('boss-content');root.replaceChildren();$('boss-catalog-controls').hidden=true;$('boss-title').textContent=profile.name;document.title=profile.name+' — Escape from Tarkov Wiki';
 const hero=el('section','','boss-hero'),portrait=el('div','','boss-portrait');portrait.append(el('span',profile.name[0],'boss-portrait-fallback'));
 if(profile.imagePortraitLink)portrait.append(image(profile.imagePortraitLink,profile.name));
 const text=el('div');text.append(el('span',profile.faction,'eyebrow'),el('h2',profile.name),el('p',profile.description));const stats=el('div','','boss-stat');stats.append(el('span','Общее здоровье'),el('strong',num(profile.healthTotal)));text.append(stats);hero.append(portrait,text);root.append(hero);
 const about=section('Описание и поведение');for(const note of profile.notes||[])about.append(el('p',publicAttribution(note)));root.append(about,healthPanel(profile));
 const wikiMaps=section('Обычные локации по Wiki'),mapLinks=el('div','','boss-links');for(const slug of profile.canonicalMapSlugs||[]){const map=reference.maps.find(m=>m.normalizedName===slug);if(map)mapLinks.append(mapLink(map));}if(mapLinks.children.length)wikiMaps.append(mapLinks);else wikiMaps.append(el('p','Отдельные локации этой разновидности не подтверждены Wiki.'));
 wikiMaps.append(el('p','Справочные обычные места появления. Специальные события и условия могут добавлять другие локации.','boss-source-note'));root.append(wikiMaps);
 const encounters=section(`Появление в режиме ${mode==='pve'?'PVE':'PVP'}`);encounters.append(el('p',`Данные на ${new Date(reference.meta.fetchedAt).toLocaleString('ru-RU')}. Вероятность относится к условиям записи, а не к текущему рейду.`,'boss-source-note'));
 const spawns=bossSpawns(profile,reference);for(const spawn of spawns){const card=el('article','','boss-spawn');card.append(el('h4',spawn.map.name),el('p',`Шанс спавна: ${num(spawn.spawnChance*100)}%.${spawn.viaGroup?' Появление в составе «Головорезов».':''}`));
  const zones=(spawn.spawnLocations||[]).map(z=>z.name).filter(Boolean);if(zones.length)card.append(el('p','Зоны: '+[...new Set(zones)].join(' · ')));
  if(spawn.spawnTrigger)card.append(el('p','Появление зависит от дополнительного условия рейда.','boss-source-note'));
  const link=mapLink(spawn.map,'Открыть локацию на карте');const url=new URL(link.href,location.href);url.searchParams.set('category','boss-zone');url.searchParams.set('q',spawn.viaGroup?'Головорезы':profile.name);link.href=url.toString();card.append(link);encounters.append(card);
 }if(!spawns.length)encounters.append(el('p','В этом снимке нет отдельной записи появления. Это не доказывает отсутствие босса в игре.'));root.append(encounters);
 const others=relatedBosses(profile,catalog.profiles);if(others.length){const related=section('Связанные боссы');for(const boss of others){const a=el('a',`${boss.name} · ${num(boss.healthTotal)} здоровья`,'button');a.href=href(boss);related.append(a);}root.append(related);}
 const sources=section('Источники');for(const source of profile.sources||[])sources.append(external('Wiki ↗',source.url));root.append(sources);
 const back=el('a','← Все боссы','button');back.href=siteHref(`boss.html?mode=${mode}`);root.prepend(back);
}
function listing(){
 if(!catalog?.profiles)return;
 $('boss-catalog-controls').hidden=false;$('boss-title').textContent='Боссы Таркова';document.title='Боссы — Escape from Tarkov Wiki';
 const query=normalize($('boss-search').value).split(/\s+/).filter(Boolean),profiles=catalog.profiles.filter(b=>query.every(w=>normalize(b.name+' '+b.faction+' '+b.description).includes(w)));
 const prefs=readStorage(prefsKey())||{};writeStorage(prefsKey(),{...prefs,bossPage:{...(prefs.bossPage||{}),query:$('boss-search').value}});
 $('boss-count').textContent=`Найдено: ${profiles.length}`;const root=$('boss-content');root.replaceChildren();const cards=el('div','','boss-catalog');
 for(const profile of profiles){const card=el('a','','boss-card');card.href=href(profile);card.append(el('span',profile.faction,'eyebrow'),el('h2',profile.name),el('p',profile.description),el('strong',`${num(profile.healthTotal)} здоровья`),el('span','Описание и части тела →','boss-card-more'));cards.append(card);}root.append(profiles.length?cards:empty('Боссы не найдены. Измените поиск.'));
}
async function load(){
 const current=++request;$('boss-mode').value=mode;$('boss-content').replaceChildren(empty('Загрузка боссов…'));
 try{const [data,api]=await Promise.all([json('data/guide/bosses.json'),freshDataset('reference-'+mode,`data/guide/reference-${mode}.json`)]);if(current!==request)return;catalog=data;reference=localizeDisplay(api);
  $('boss-back').href=siteHref(`reference.html?tab=bosses&mode=${mode}`);const top=$('boss-guide');if(top)top.href=$('boss-back').href;
  $('boss-search').value=readStorage(prefsKey())?.bossPage?.query||'';
  const id=pageParams().get('boss');if(id){const profile=findBoss(id,catalog.profiles);if(profile)detail(profile);else{$('boss-content').replaceChildren(empty('Босс не найден. Откройте общий каталог.'));$('boss-catalog-controls').hidden=false;}}else listing();
 }catch{if(current===request)$('boss-content').replaceChildren(empty('Не удалось загрузить справочник боссов. Обновите страницу.'));}
}
$('boss-search').oninput=listing;
$('boss-mode').onchange=()=>{mode=$('boss-mode').value==='pve'?'pve':'regular';writeStorage('kord-atlas-game-mode',mode);const url=new URL(location.href);url.searchParams.set('mode',mode);history.replaceState(null,'',canonicalURL(url));return load();};
globalThis.addEventListener('wiki:state',event=>{if(event.detail?.key===prefsKey()&&!params.has('boss')){$('boss-search').value=readStorage(prefsKey())?.bossPage?.query||'';listing();}});
await load();
