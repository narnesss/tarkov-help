import {json,readStorage,writeStorage,el} from './guide-common.mjs?v=release-20261016';
import {resolveGameMode} from './game-mode.mjs';
import {normalize} from './model.mjs';
import {siteHref,canonicalURL} from './site-routes.mjs?v=release-20261016';
import {questHref,catalogHref} from './quest-catalog-model.mjs?v=release-20261016';
const $=id=>document.getElementById(id);
let mode=resolveGameMode(new URLSearchParams(location.search).get('mode'),readStorage('kord-atlas-game-mode')),home,index,indexRequest,searchSequence=0;
const arrow=()=>{const svg=document.createElementNS('http://www.w3.org/2000/svg','svg'),use=document.createElementNS('http://www.w3.org/2000/svg','use');use.setAttribute('href','#home-arrow');svg.setAttribute('aria-hidden','true');svg.append(use);return svg;};
function relink(){
 for(const link of document.querySelectorAll('[data-home-link]')){
  const url=new URL(link.dataset.homeLink,location.origin+'/');url.searchParams.set('mode',mode);link.href=siteHref(url.pathname+url.search);
 }
 $('home-search-mode').value=mode;$('home-search-form').setAttribute('action',siteHref('quests.html'));
 for(const button of document.querySelectorAll('.home-mode button'))button.setAttribute('aria-pressed',String(button.dataset.mode===mode));
}
function journal(){
 if(!home)return;const snapshot=home.modes[mode],progress=readStorage('kord-atlas-quests-'+mode+'-v1')?.quests||{},main=snapshot.tasks.filter(task=>!task.archive),done=main.filter(task=>progress[task.id]?.status==='complete').length;
 $('home-progress').max=snapshot.total;$('home-progress').value=done;$('home-progress-label').textContent=done+' из '+snapshot.total+' заданий';
 const selected=readStorage('kord-atlas-quest-view-'+mode+'-v1')?.selected;
 const current=main.find(task=>task.id===selected&&progress[task.id]?.status!=='complete')||main.find(task=>progress[task.id]?.status==='active');
 $('home-continue').href=current?questHref(current.id,mode):catalogHref('all',mode);
 $('home-journal-description').textContent=current?'Последнее задание: «'+current.name+'». Вернись к целям и продолжи прохождение.':'Отмечай выполненные задания и возвращайся к своим целям.';
}
function traders(){
 if(!home)return;const root=$('home-traders');root.replaceChildren();
 for(const trader of home.modes[mode].traders.slice(0,8)){
  const link=el('a','','home-trader');link.dataset.homeLink='quests.html?trader='+encodeURIComponent(trader.id);link.href=catalogHref(trader.id,mode);
  if(trader.image){const img=el('img');img.src=trader.image;img.alt='';img.width=58;img.height=64;img.loading='lazy';link.append(img);}
  link.append(el('strong',trader.name),el('span',trader.total+' заданий'));root.append(link);
 }
}
function applyMode(next,persist=true){
 mode=resolveGameMode(next);if(persist)writeStorage('kord-atlas-game-mode',mode);
 const url=new URL(location.href);url.searchParams.set('mode',mode);history.replaceState(null,'',canonicalURL(url));
 relink();if(home){$('home-quest-total').textContent=home.modes[mode].total;$('home-kappa-total').textContent=home.modes[mode].kappa;traders();journal();}renderSearch();
}
async function renderSearch(){
 const sequence=++searchSequence,query=normalize($('home-search').value.trim()),root=$('home-search-results'),list=$('home-search-list');
 if(query.length<2){root.hidden=true;list.replaceChildren();return;}
 root.hidden=false;$('home-search-status').textContent='Ищем в справочнике…';
 try{
  if(!home)home=await json('data/home.json');
  if(!index)index=await(indexRequest??=json('data/page-routes.json').catch(error=>{indexRequest=null;throw error;}));
  if(sequence!==searchSequence)return;
  const snapshot=home.modes[mode],traderNames=new Map(snapshot.traders.map(trader=>[trader.id,trader.name]));
  const records=[...snapshot.tasks.map(task=>({name:task.name,kind:task.story?'Основной сюжет':task.archive?'Архивный квест':'Квест',detail:traderNames.get(task.trader)||'',href:questHref(task.id,mode)})),...home.maps.map(map=>({name:map.name,kind:'Карта',href:siteHref('raid.html?'+new URLSearchParams({map:map.id,mode}))})),...home.bosses.map(boss=>({name:boss.name,kind:'Босс',href:siteHref('boss.html?'+new URLSearchParams({boss:boss.slug,mode}))})),...Object.entries(index.items).filter(([,item])=>!/^\s*(?:none|debug|test item|unknown)\b/i.test(item.name)).map(([id,item])=>({name:item.name,kind:'Предмет',href:siteHref('reference.html?'+new URLSearchParams({tab:'items',item:id,mode}))}))];
  const words=query.split(/\s+/),matches=records.filter(record=>words.every(word=>normalize(record.name).includes(word)));
  matches.sort((a,b)=>(normalize(b.name)===query)-(normalize(a.name)===query)||(normalize(b.name).startsWith(query))-(normalize(a.name).startsWith(query))||a.name.localeCompare(b.name,'ru'));
  list.replaceChildren();$('home-search-status').textContent=matches.length?'Найдено: '+matches.length+(matches.length>8?' · первые 8 результатов':''):'Ничего не найдено. Попробуй другое название.';
  for(const result of matches.slice(0,8)){
   const link=el('a'),copy=el('div');link.href=result.href;copy.append(el('small',result.kind+(result.detail?' · '+result.detail:'')),el('strong',result.name));link.append(copy,el('span','Открыть'),arrow());list.append(link);
  }
 }catch{if(sequence===searchSequence){list.replaceChildren();$('home-search-status').textContent='Поиск временно недоступен. Разделы справочника можно открыть ниже.';}}
}
$('home-search').oninput=renderSearch;
$('home-search').onkeydown=event=>{if(event.key==='Escape'){$('home-search-results').hidden=true;}else if(event.key==='ArrowDown'){const first=$('home-search-list').querySelector('a');if(first){event.preventDefault();first.focus();}}};
$('home-search-form').onsubmit=async event=>{event.preventDefault();if(!$('home-search').value.trim()){$('home-search').focus();return;}await renderSearch();const first=$('home-search-list').querySelector('a');location.assign(first?.href||new URL(catalogHref('all',mode,{q:$('home-search').value.trim()}),location.origin).href);};
for(const button of document.querySelectorAll('.home-mode button'))button.onclick=()=>applyMode(button.dataset.mode);
globalThis.addEventListener('wiki:state',event=>{if(event.detail?.key==='kord-atlas-game-mode'){const next=readStorage('kord-atlas-game-mode');if(next!==mode)applyMode(next,false);}else if([`kord-atlas-quests-${mode}-v1`,`kord-atlas-quest-view-${mode}-v1`].includes(event.detail?.key))journal();});
relink();
try{home=await json('data/home.json');traders();journal();$('home-quest-total').textContent=home.modes[mode].total;$('home-kappa-total').textContent=home.modes[mode].kappa;}catch{/* Server-rendered navigation and counts remain available. */}
