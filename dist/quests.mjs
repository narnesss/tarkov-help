import {siteHref,pageParams,canonicalURL} from './site-routes.mjs';
import {questChoiceIndex,questChoicePlan} from './quest-choice-model.mjs';
import {QuestMapView} from './quest-map-view.mjs';
import {questHref,catalogHref,catalogRoute,sectionTasks,catalogGroups,questPreview} from './quest-catalog-model.mjs';
import {QuestObjectiveWorkspace} from './quest-objective-workspace.mjs';
import {QuestSaveFeedback} from './quest-save-feedback.mjs';
import {setObjective} from './quest-workspace-model.mjs';
import {rewardLabels,resolveAchievement,rewardAchievementNames,groupRewardLines,rewardTradeUnlocks} from './quest-reward-model.mjs';
import {hasQuestRoadmap,questGalleryPlan} from './quest-view-model.mjs';
import {resolveGameMode} from './game-mode.mjs';
import {ruText} from './ru-data.mjs';
import {questExtras,enrichQuests} from './quest-extras.mjs';
import {questBriefing} from './quest-briefing.mjs';
import {galleryBlock} from './gallery.mjs';
import {objectiveConditions,objectiveGuide,questOverview} from './quest-details.mjs';
import {orderedObjectives} from './roadmap-model.mjs';
import {TicketQuestMap} from './ticket-quest-map.mjs';
import {GunsmithView} from './gunsmith-ui.mjs';
import {itemReferences,resolveQuestItem} from './quest-items.mjs';
import {$,el,num,image,external,json,readStorage,writeStorage,toast,download,apiDataset,cached,section,action,empty} from './guide-common.mjs';
import {QUEST_STATES,idOf,list,mapAlias,taskMaps,sanitizeQuestProgress,questState,availability,requirementStatus,followingTasks,filterQuests,chainColumns} from './quest-model.mjs';

const params=pageParams();
let mode=resolveGameMode(params.get('mode'),readStorage('kord-atlas-game-mode'));
let extras,data,tasks=[],byId=new Map(),traders=new Map(),maps=new Map(),progress,selected,filterTrader='all',limit=70,request=0,roadmap;
let objectiveWorkspace,questMap,choices=new Map(),route=catalogRoute(location.href),returnCatalog;

function catalogFilters(){return {q:$('quest-search').value,map:$('quest-map').value,status:$('quest-status').value,flag:$('quest-flag').value,sort:$('quest-sort').value};}
function updateParams(){for(const key of [...params.keys()])params.delete(key);for(const [key,value] of pageParams())params.set(key,value);}
function navigate(href,{replace=false}={}){
  const target=new URL(href,location.href);
  if(target.href!==location.href)(replace?history.replaceState:history.pushState||history.replaceState).call(history,null,'',target);
  if(route.kind==='catalog'&&catalogRoute(target.href).kind==='quest')returnCatalog=catalogHref(route.section,mode,catalogFilters());
  updateParams();route=catalogRoute(location.href);closeFilters(false);feedback.clearUndo();
  if(route.kind==='quest'){selected=byId.get(route.id);}
  else{selected=null;filterTrader=route.section;restoreView();}
  renderRoute();globalThis.scrollTo?.({top:0,behavior:'auto'});
  const heading=document.querySelector(route.kind==='quest'?'.quest-hero h1':'#quest-catalog-title');heading?.setAttribute('tabindex','-1');heading?.focus({preventScroll:true});
}
function routeLink(label,href,className='text-button'){
 const a=el('a',label,className);a.href=href;a.onclick=e=>{if(e&&(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||e.button>0))return;e?.preventDefault();navigate(href);};return a;
}
function backHref(){if(returnCatalog){const url=new URL(returnCatalog,location.href);url.searchParams.set('mode',mode);return url.pathname+url.search;}return catalogHref(selected?.story?'story':selected?.catalogSection==='supplementary'?'archive':idOf(selected?.trader)||'all',mode,catalogFilters());}
function closeFilters(focus=true){const dialog=$('quest-filters-dialog'),layout=$('quest-layout');if(dialog.open)dialog.close();layout.prepend(document.querySelector('.quest-toolbar'));layout.classList.remove('show-filters');$('quest-mobile-filters').setAttribute('aria-expanded','false');if(focus)$('quest-mobile-filters').focus();}

const progressKey=()=>`kord-atlas-quests-${mode}-v1`;
const entry=t=>progress.quests[t.id]||{status:'notstarted',favorite:false,note:'',objectives:[]};
const feedback=new QuestSaveFeedback(progressKey);
const viewKey=()=>`kord-atlas-quest-view-${mode}-v1`;
function saveView(){const {tab,...previous}=readStorage(viewKey())||{};writeStorage(viewKey(),{...previous,trader:filterTrader,query:$('quest-search').value,map:$('quest-map').value,status:$('quest-status').value,flag:$('quest-flag').value,sort:$('quest-sort').value,selected:selected?.id});}
function restoreView(){
 const view=readStorage(viewKey())||{};filterTrader=route.kind==='catalog'?route.section:'all';
 const restore=route.kind==='quest';$('quest-search').value=params.get('q')||(restore&&typeof view.query==='string'?view.query:'');
 for(const key of ['map','status','flag','sort']){const control=$('quest-'+key),value=params.get(key)||(restore?view[key]:undefined);control.value=[...control.options].some(o=>o.value===value)?value:control.options[0]?.value||'';}return view;
}
function save(){writeStorage(progressKey(),progress);}
function update(t,patch){progress.quests[t.id]={...entry(t),...patch};save();renderTraders();renderList();renderHeader();}
function taskButton(task,subtitle=''){const b=routeLink(task.name,questHref(task.id,mode),'quest-link');b.append(el('small',subtitle||`${traders.get(idOf(task.trader))?.name||'Торговец'} · ${task.story?'Сюжет':'от '+(task.minPlayerLevel||1)+' ур.'} · ${QUEST_STATES[questState(task,progress)]}`));return b;}
function itemChip(ref,count=1){
  if(Array.isArray(ref)){const group=el('div','','objective-items');for(const item of itemReferences(ref))group.append(itemChip(item,count));return group;}
  const item=resolveQuestItem(ref,data.items,data.questItems);
  if(!item)return el('span','Название предмета пока недоступно в источнике','quest-item-unavailable');
  const a=el('a','','guide-item');a.href=siteHref(`reference.html?tab=items&item=${encodeURIComponent(item.id)}&mode=${mode}`);
  if(item.iconLink)a.append(image(item.iconLink));a.append(el('span',item.name),el('b','× '+num(count)));return a;
}
function keyGroups(value){
  const root=el('div','','objective-key-groups');
  for(const group of value||[]){const refs=itemReferences(group);if(!refs.length)continue;const wrap=el('div','','objective-items');
    if(refs.length>1)wrap.append(el('span','Подойдёт один из ключей:','quest-item-alternatives'));
    for(const ref of refs)wrap.append(itemChip(ref));root.append(wrap);
  }return root;
}
function mapLink(mapId,questId,label='На карте',objective){const map=maps.get(idOf(mapId));if(!map)return null;const a=el('a',`${label}: ${map.name}`,'button');a.href=siteHref(`raid.html?map=${encodeURIComponent(mapAlias(map.normalizedName))}&quest=${encodeURIComponent(questId)}&mode=${mode}${objective?'&objective='+encodeURIComponent(objective):''}`);a.onclick=e=>{if(e&&(e.metaKey||e.ctrlKey||e.shiftKey||e.altKey||e.button>0))return;if(questMap?.focus(mapAlias(map.normalizedName),objective)){e?.preventDefault();}};return a;}
function statusName(task){if(task.catalogUnavailableModes?.includes(mode))return 'Недоступен в '+mode.toUpperCase();const state=questState(task,progress);if(state!=='notstarted')return QUEST_STATES[state];const a=availability(task,progress);return a.available?(a.external?'Проверить условия':'По цепочке доступен'):'Закрыт';}
function renderTraders(){
 const main=sectionTasks(tasks,'all'),total=main.filter(t=>questState(t,progress)==='complete').length;$('quest-done').textContent=num(total);$('quest-total').textContent=` / ${num(main.length)} выполнено`;$('quest-progress').max=main.length;$('quest-progress').value=total;
 const groups=catalogGroups(tasks,list(data.traders),progress),story=groups.find(tr=>tr.id==='story');
 $('quest-kappa-link').href=catalogHref('all',mode,{flag:'kappa'});$('quest-kappa-link').textContent='Для Каппы · '+main.filter(t=>t.kappaRequired).length;$('quest-kappa-link').onclick=e=>{if(e&&(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||e.button>0))return;e?.preventDefault();navigate(catalogHref('all',mode,{flag:'kappa'}));};
 $('quest-archive-link').href=catalogHref('archive',mode);$('quest-archive-link').textContent='Другие и архивные · '+sectionTasks(tasks,'archive').length;$('quest-archive-link').onclick=e=>{if(e&&(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||e.button>0))return;e?.preventDefault();navigate(catalogHref('archive',mode));};
 $('quest-all-link').href=catalogHref('all',mode);$('quest-all-link').onclick=e=>{if(e&&(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||e.button>0))return;e?.preventDefault();navigate(catalogHref('all',mode));};
 $('quest-story-card').replaceChildren();
 if(story){const card=routeLink('',catalogHref('story',mode),'quest-story-card'),copy=el('div'),title=el('h2','Основной сюжет');copy.append(el('span','ПУТЬ ИЗ ТАРКОВА','eyebrow'),title,el('p','Сюжетные главы, решения и концовки. Откройте главу, чтобы продолжить прохождение.'));const tail=el('div','','quest-story-action'),meter=el('progress');meter.max=story.total;meter.value=story.done;meter.setAttribute('aria-label','Выполнено сюжетных глав');tail.append(el('strong','Открыть главы →'),el('small',`${story.total} глав · ${story.done} выполнено`),meter);card.append(copy,tail);$('quest-story-card').append(card);}
 $('quest-traders').replaceChildren(...groups.filter(tr=>tr.id!=='story').map(tr=>{
  const card=routeLink('',catalogHref(tr.id,mode),'trader-button quest-trader-card');card.setAttribute('aria-label',`Квесты ${tr.name}: ${tr.total} заданий`);if(tr.imageLink)card.append(image(tr.imageLink));else card.append(el('span',tr.name.slice(0,2),'trader-initials'));
  const copy=el('div','','quest-trader-copy'),meter=el('progress');meter.max=tr.total;meter.value=tr.done;meter.setAttribute('aria-label','Выполнено заданий');copy.append(el('strong',tr.name),el('small',`${num(tr.total)} заданий · ${num(tr.done)} выполнено`),meter);card.append(copy,el('span','→','quest-card-arrow'));return card;
 }));
}
function rewardPreview(t){
 const box=el('div','','quest-row-rewards');if(t.experience)box.append(el('strong','+'+num(t.experience)+' EXP'));
 const currencies={'5449016a4bdc2d6f028b456f':'₽','5696686a4bdc2da3298b456a':'$','569668774bdc2da2298b4568':'€'};let types=0;
 for(const reward of t.finishRewards?.items||[]){const currency=currencies[idOf(reward.item)];if(currency)box.append(el('span',num(reward.count)+' '+currency));else types++;}
 if(types)box.append(el('span',`${types} ${types===1?'предмет':'предм.'}`));
 if(!box.children.length)box.append(el('span',t.story?'По выбранной ветке':'Подробности в квесте'));return box;
}
function renderCatalogHeader(){
 const section=route.section||'hub',tr=traders.get(section);let title='Квесты',description='Выберите торговца или главу основного сюжета, чтобы открыть задания.';
 if(section==='story'){title='Основной сюжет';description='Главы в порядке прохождения. Откройте задание, чтобы увидеть все цели и варианты прохождения.';}
 else if(section==='archive'){title='Другие и архивные';description='Дополнительные и архивные задания. Все ваши отметки и подробности сохранены.';}
 else if(section==='all'){title='Все квесты';description='Задания торговцев и основного сюжета. Найдите нужный квест по названию, цели или локации.';}
 else if(tr){title='Квесты: '+tr.name;description='Условия и награды — в списке. Подробное прохождение, изображения и отметки целей — на странице квеста.';}
 $('quest-catalog-title').textContent=title;$('quest-catalog-description').textContent=description;document.title=title+' — Escape from Tarkov Wiki';
 const crumbs=$('quest-catalog-breadcrumbs');crumbs.replaceChildren();if(section!=='hub')crumbs.append(routeLink('Квесты',catalogHref('hub',mode)),el('span','›'),el('span',title));
 $('quest-list-title').textContent=section==='story'?'Сюжетные главы':section==='hub'?'Результаты поиска':'Список заданий';
}
function renderList(){
 saveView();if(route.kind==='quest')return;
 const filters=catalogFilters(),isHub=route.section==='hub'&&!filters.q&&['map','status','flag'].every(key=>filters[key]==='all');
 $('quest-layout').dataset.view=isHub?'hub':'list';renderCatalogHeader();
 const own=sectionTasks(tasks,route.section),result=filterQuests(own,{query:filters.q,trader:'all',map:filters.map,status:filters.status,flag:filters.flag,sort:filters.sort,progress,maps:list(data.maps)});
 if(route.section==='story'&&filters.sort==='level')result.sort((a,b)=>tasks.indexOf(a)-tasks.indexOf(b));
 $('quest-count').textContent=`${num(result.length)} заданий`;
 history.replaceState(null,'',new URL(catalogHref(route.section,mode,filters),location.href));updateParams();
 $('quest-list').replaceChildren(...result.slice(0,limit).map(t=>{
  const a=routeLink('',questHref(t.id,mode),'quest-row quest-catalog-row');a.dataset.quest=t.id;a.classList.toggle('is-complete',questState(t,progress)==='complete');
  const title=el('div','','quest-row-name');title.append(el('strong',(entry(t).favorite?'★ ':'')+t.name),el('small',`${t.story?'Сюжетная глава':t.catalogLevelUnknown?'Уровень не указан':(t.minPlayerLevel||1)+' ур.'} · ${traders.get(idOf(t.trader))?.name||''}`));if(t.catalogKind==='event')title.append(el('span','Событие','quest-tag'));if(t.catalogKind==='seasonal')title.append(el('span','Сезонное','quest-tag'));if(t.catalogGame==='arena')title.append(el('span','ARENA','quest-tag'));if(t.catalogGame==='prestige')title.append(el('span','ПРЕСТИЖ · PVP','quest-tag'));if(t.lightkeeperRequired)title.append(el('span','Для Смотрителя','quest-tag'));
  const preview=questPreview(t,mode),goals=el('div','','quest-row-objectives');for(const text of preview.objectives)goals.append(el('span',text));if(preview.more)goals.append(el('small',`Ещё ${preview.more} целей →`));
  const state=el('div','','quest-row-progress'),objectives=orderedObjectives(t,entry(t).storyBranch,mode),done=objectives.filter(o=>entry(t).objectives.includes(o.id)).length,meter=el('progress');meter.max=objectives.length||1;meter.value=done;meter.setAttribute('aria-label','Выполнение целей');state.append(el('span',statusName(t),'quest-row-status'),el('span',`${done} из ${objectives.length} целей`),meter);
  a.append(title,goals,rewardPreview(t),state,el('span',t.kappaRequired?'Да':'Нет','quest-row-kappa'+(t.kappaRequired?' is-required':'')));const wrapper=el('div','','quest-list-entry');wrapper.setAttribute('role','listitem');wrapper.append(a);return wrapper;
 }));
 if(!result.length)$('quest-list').append(empty('Подходящих заданий нет. Измените запрос или сбросьте фильтры.'));$('quest-more').hidden=result.length<=limit;
}
function renderRoute(){
 const layout=$('quest-layout');layout.classList.toggle('detail-open',route.kind==='quest');
 if(route.kind==='quest'){
  layout.dataset.view='quest';selected=byId.get(route.id);if(selected){renderDetail();}
  else{questMap?.destroy();questMap=null;$('quest-detail').replaceChildren(empty('Задание не найдено в этом режиме.'),routeLink('← К каталогу квестов',catalogHref('hub',mode),'button'));document.title='Квест не найден — Escape from Tarkov Wiki';}
 }else{objectiveWorkspace=null;questMap?.destroy();questMap=null;roadmap?.destroy();roadmap=null;feedback.root=null;$('quest-detail').replaceChildren();selected=null;filterTrader=route.section;renderList();}
}
function selectTask(id,tab='objectives'){if(!byId.has(id))return;if(route.kind==='catalog')returnCatalog=catalogHref(route.section,mode,catalogFilters());navigate(questHref(id,mode,tab));}
function continueSelectedGoal(){objectiveWorkspace?.continue();}
function renderHeader(){if(!selected)return;const state=questState(selected,progress),button=$('selected-complete');if(button){button.textContent=state==='complete'?'✓ Выполнено':'Отметить выполненным';button.setAttribute('aria-pressed',String(state==='complete'));button.setAttribute('aria-label',state==='complete'?'Снять отметку о выполнении':'Отметить выполненным');}const goals=orderedObjectives(selected,entry(selected).storyBranch,mode),done=goals.filter(o=>entry(selected).objectives.includes(o.id)).length,label=$('selected-objective-progress'),meter=$('selected-objective-meter');if(label)label.textContent=`${done} из ${goals.length} целей`;if(meter){meter.max=goals.length||1;meter.value=done;}}
function renderDetail(){
  const t=selected;if(!t)return;document.title=t.name+' — Escape from Tarkov Wiki';objectiveWorkspace=null;questMap?.destroy();questMap=null;roadmap?.destroy();roadmap=null;saveView();const url=new URL(location.href);url.searchParams.delete('tab');url.pathname='/quest.html';url.searchParams.set('quest',t.id);history.replaceState(null,'',canonicalURL(url));updateParams();route=catalogRoute(location.href);const root=$('quest-detail');root.replaceChildren();
  const hero=el('header','','quest-hero');
  const tr=traders.get(idOf(t.trader));const breadcrumbs=el('nav','','quest-breadcrumbs');breadcrumbs.setAttribute('aria-label','Путь к заданию');breadcrumbs.append(routeLink('Квесты',catalogHref('hub',mode)),el('span','›'),routeLink(t.story?'Основной сюжет':tr?.name||'Торговец',catalogHref(t.story?'story':t.catalogSection==='supplementary'?'archive':idOf(t.trader),mode)),el('span','›'),el('span',t.name));hero.append(breadcrumbs,routeLink('← К списку заданий',backHref(),'text-button quest-back'));const meta=el('div','','quest-meta');if(tr?.imageLink)meta.append(image(tr.imageLink));meta.append(el('span',tr?.name||'Торговец'),el('span',t.story?'СЮЖЕТНАЯ ГЛАВА':t.catalogLevelUnknown?'УРОВЕНЬ НЕ УКАЗАН':`${t.minPlayerLevel||1} УРОВЕНЬ`,'quest-tag'));if(t.catalogSection==='supplementary')meta.append(el('span','Другие и архивные','quest-tag'));if(t.catalogKind==='event')meta.append(el('span','Событие · 1.1.5','quest-tag'));if(t.catalogKind==='seasonal')meta.append(el('span','Сезонное','quest-tag'));if(t.catalogGame==='arena')meta.append(el('span','ARENA','quest-tag'));if(t.catalogGame==='prestige')meta.append(el('span','ПРЕСТИЖ · PVP','quest-tag'));if(t.kappaRequired)meta.append(el('span','Каппа','quest-tag'));if(t.lightkeeperRequired)meta.append(el('span','Смотритель','quest-tag'));hero.append(meta,el('h1',t.name));renderChoiceNotice(hero,t);
  meta.append(el('span',mode==='pve'?'PVE':'PVP','quest-tag'));
  const actions=el('div','','quest-actions');const complete=action('Отметить выполненным',()=>{const previous=questState(t,progress),next=previous==='complete'?'notstarted':'complete';update(t,{status:next});feedback.offerUndo(next==='complete'?'Задание отмечено выполненным':'Отметка выполнения снята',()=>update(t,{status:previous}));},'button quest-done');complete.id='selected-complete';complete.setAttribute('aria-pressed',String(questState(t,progress)==='complete'));actions.append(complete);hero.append(actions);
  const progressBar=el('div','','selected-quest-progress');const progressLabel=el('strong');progressLabel.id='selected-objective-progress';const meter=el('progress');meter.id='selected-objective-meter';meter.setAttribute('aria-label','Выполнение целей выбранного задания');const resume=action('К текущей цели',continueSelectedGoal,'button quest-resume');resume.id='quest-resume';progressBar.append(progressLabel,meter,resume);hero.append(progressBar);const saveStatus=el('div','','quest-save-status');hero.append(saveStatus);feedback.mount(saveStatus);root.append(hero);
  const content=el('div','','quest-content');content.id='quest-content';root.append(content);
  const grid=el('div','','quest-reading-grid'),main=el('div','','quest-reading-main'),side=el('aside','','quest-reading-side');grid.append(main,side);content.append(grid);renderObjectives(main,t);renderReadingSide(side,t);renderHeader();
}
function renderChoiceNotice(root,t){
 const plan=questChoicePlan(t,choices,tasks);if(!plan)return;
 const box=el('aside','','quest-choice-notice');box.id='quest-choice-notice';box.dataset.kind=plan.alternatives.length?'exclusive':plan.branches.length?'branch':'consequences';box.setAttribute('role','note');box.setAttribute('aria-label','Варианты и последствия выполнения задания');
 box.append(el('strong',plan.alternatives.length||plan.branches.length?'Задание с выбором':'Выбор влияет на цепочку','quest-choice-heading'));
 const genitive={'Прапор':'Прапора','Терапевт':'Терапевта','Лыжник':'Лыжника','Миротворец':'Миротворца','Барахольщик':'Барахольщика','Егерь':'Егеря','Смотритель':'Смотрителя','Скупщик':'Скупщика','Реф':'Рефа','Водитель БТР':'Водителя БТР'};
 const appendOptions=(node,options,join='либо')=>{for(const [i,option] of options.entries()){
  if(i)node.append(document.createTextNode(i===options.length-1?' '+join+' ':', '));
  const [first,...variants]=option.tasks;
  node.append(routeLink(option.name+(option.name===t.name&&option.trader===idOf(t.trader)?' (другой вариант)':''),questHref(first.id,mode),'quest-choice-link quest-choice-option'));
  if(variants.length){node.append(document.createTextNode(' ('));for(const [index,variant] of variants.entries()){if(index)node.append(document.createTextNode(', '));const link=routeLink('другой вариант',questHref(variant.id,mode),'quest-choice-link quest-choice-variant');link.title=variant.name;node.append(link);}node.append(document.createTextNode(')'));}
  const trader=traders.get(option.trader);if(trader){node.append(document.createTextNode(' от '),routeLink(genitive[trader.name]||trader.name,catalogHref(trader.id,mode),'quest-choice-link quest-choice-trader'));}
 }};
 if(plan.alternatives.length){const paragraph=el('p','Данный квест с несколькими вариантами выбора. Вы можете выполнить этот квест либо ','quest-choice-alternatives');appendOptions(paragraph,plan.alternatives);paragraph.append(document.createTextNode('.'));box.append(paragraph);}
 const effects=plan.alternatives.length&&(plan.failedBy.length||plan.fails.length)?el('details','','quest-choice-effects'):box;
 if(effects!==box){effects.append(el('summary','Другие последствия выбора'));box.append(effects);}
 if(plan.failedBy.length){const paragraph=el('p','Если сначала выполнить ','quest-choice-incoming');appendOptions(paragraph,plan.failedBy);paragraph.append(document.createTextNode(', текущий квест будет провален.'));effects.append(paragraph);}
 if(plan.fails.length){const paragraph=el('p','Если сначала выполнить этот квест, будут провалены ещё не завершённые задания: ','quest-choice-outgoing');appendOptions(paragraph,plan.fails,'и');paragraph.append(document.createTextNode('.'));effects.append(paragraph);}
 for(const branch of plan.branches)box.append(el('p',branch.text));
 for(const note of plan.notes){const paragraph=el('p',note.text);if(note.questId){const target=byId.get(note.questId);paragraph.append(routeLink('«'+target.name+'»',questHref(target.id,mode),'quest-choice-link'),document.createTextNode('.'));}box.append(paragraph);}
 const completed=plan.alternatives.flatMap(option=>option.tasks).filter(task=>questState(task,progress)==='complete');
 if(completed.length){const paragraph=el('p','В вашем журнале уже отмечен другой вариант: ','quest-choice-recorded');for(const [i,task] of completed.entries()){if(i)paragraph.append(document.createTextNode(', '));paragraph.append(routeLink(task.name,questHref(task.id,mode),'quest-choice-link'));}paragraph.append(document.createTextNode('.'));box.append(paragraph);}
 const source=plan.sources[0]||plan.branches[0]?.sourceUrl;
 if(source)box.append(external('Источник: Вики ↗',source));
 root.append(box);
}
function renderReadingSide(root,t){
 const card=section('Данные квеста');card.classList.add('quest-data-panel');const tr=traders.get(idOf(t.trader));if(tr?.imageLink)card.append(image(tr.imageLink,'','quest-data-portrait'));
 const fields=el('dl','','quest-data-list');for(const [label,value] of [['Торговец',tr?.name||'Основной сюжет'],['Уровень',t.minPlayerLevel||'Не указан'],['Цели',orderedObjectives(t,entry(t).storyBranch,mode).length],['Для Каппы',t.kappaRequired?'Да':'Нет'],['Опыт',!t.story&&t.questContent?.experience!=null?'+'+num(t.questContent.experience):t.experience?'+'+num(t.experience):t.story?'По выбранной ветке':'Не указан']]){fields.append(el('dt',label),el('dd',String(value)));}card.append(fields);root.append(card);
 const chain=section('Цепочка заданий');chain.id='quest-chain-section';chain.classList.add('quest-inline-chain');renderChain(chain,t,{compact:true});root.append(chain);
 const rewards=section('Награды');rewards.id='quest-rewards-section';rewards.classList.add('quest-inline-rewards');renderRewards(rewards,t);root.append(rewards);
 const next=section(t.story?'Другие главы':'Ещё у торговца');const own=sectionTasks(tasks,t.story?'story':idOf(t.trader)).filter(task=>task.id!==t.id);for(const task of own.slice(0,5))next.append(taskButton(task));root.append(next);
}
function renderRequirements(root,t){
  const s=section('Как получить задание'),av=availability(t,progress);
  s.append(el('div',av.modeUnavailable?'Задание относится к PVP и недоступно в PVE. Его описание можно использовать как справку.':t.catalogModeNotes?.[mode]|| (t.story?'Доступность сюжетной главы зависит от событий и диалогов. Сверьте условия ниже.':av.available?(av.external?'Уровень и цепочка подходят. Проверьте дополнительные условия ниже.':'Уровень и цепочка подходят по вашим отметкам. Задание выдаёт '+(traders.get(idOf(t.trader))?.name||'торговец')+'.'):'Задание ещё закрыто по вашим отметкам. Выполните условия ниже.'),'quest-callout'+(av.external||!av.available?' warning':'')));
  const level=el('div',`${progress.level>=av.level?'✓':'○'} Уровень персонажа: не ниже ${av.level}`,'quest-requirement');if(t.minPlayerLevel!==null)s.append(level);
  for(const req of t.taskRequirements||[]){const predecessor=byId.get(idOf(req.task)),states=(req.status?.length?req.status:['complete']).map(s=>QUEST_STATES[s]||({started:'В процессе',availableForFinish:'Готов к сдаче'}[s])||s).join(' или ');if(predecessor)s.append(taskButton(predecessor,`${requirementStatus(req,progress)?'✓':'○'} Требуется статус: ${states}`));}
  for(const r of t.catalogRequirements||[])s.append(external(r.name+' ↗',r.url));
  for(const r of t.traderRequirements||[]){s.append(el('p',`${traders.get(idOf(r.trader))?.name||'Торговец'}: ${{level:'уровень лояльности',reputation:'репутация',standing:'репутация'}[r.requirementType]||r.requirementType} ${r.compareMethod||'≥'} ${num(r.value)}`));}
  if(t.factionName&&!['Any','AnyPmc'].includes(t.factionName))s.append(el('p',`Фракция: ${t.factionName}`));
  if(t.availableDelaySecondsMin||t.availableDelaySecondsMax)s.append(el('p',`Задержка открытия после условий: ${num((t.availableDelaySecondsMin||0)/3600)}–${num((t.availableDelaySecondsMax||t.availableDelaySecondsMin)/3600)} ч.`));
  if(t.requiredPrestige)s.append(el('p','Требуется престиж. Конкретную ступень проверьте в игре: источник передаёт только идентификатор.'));
  for(const r of t.otherRequirements||[]){s.append(el('p',typeof r==='string'?r:r.description||(r.type==='dialogue'?`Дополнительный диалог: ${(r.traders||[]).map(x=>traders.get(idOf(x))?.name||'торговец').join(', ')}.`:'Дополнительное сюжетное условие игры. Публичный источник не раскрывает его читаемое описание.')))}
  if(t.storyRequirements?.length){const detail=section('Сюжетные условия');for(const line of t.storyRequirements)detail.append(el('p',typeof line==='string'?line:line.description||line.text||''));s.append(detail);}
  const wiki=t.media?.wikiConditions;if(wiki?.requirements?.length){const detail=section('Дополнительные условия по Вики');for(const line of wiki.requirements)detail.append(el('p',line));detail.append(external('Источник условий ↗',wiki.sourceUrl));s.append(detail);}
  const facts=t.wikiFacts?.facts;if(facts&&(facts.levelRequired!=null||facts.previousQuestNames?.length)){const detail=section('Условия в русской Wiki');if(facts.levelRequired!=null)detail.append(el('p',`Уровень по Wiki: ${facts.levelRequired}.`));if(facts.previousQuestNames?.length)detail.append(el('p','Предыдущие задания по Wiki: '+facts.previousQuestNames.join('; ')+'.'));detail.append(external('Источник условий ↗',t.wikiFacts.source.url),el('p','Справочная версия Wiki может отличаться от текущего снимка API.','wiki-source-note'));s.append(detail);}
  const observed=mode==='pve'?t.additionalConditions?.conditions||[]:[];if(observed.length){const detail=section('Условия в режиме PVE');for(const condition of observed)detail.append(el('p',condition.label));detail.append(el('p','Наблюдение для версии 1.1.0, фракция USEC. В других версиях условия могут отличаться.','guide-footer-note'),external('Источник наблюдения ↗',extras.conditions.sourceUrl));s.append(detail);}
  root.append(s);
}
function renderObjectives(root,t){
  const briefing=questBriefing(t);if(briefing)root.append(briefing);
  if(t.story){root.classList.add('story-reading-main');renderStory(root,t);}
  const overview=el('details','','quest-overview-disclosure');overview.append(el('summary','Обзор, условия получения и варианты прохождения'));const preparation=el('div','','quest-preparation');overview.append(preparation);root.append(overview);const detailRoot=root;root=preparation;
  if(!t.story){const summary=el('div','','wiki-quest-summary');for(const line of questOverview(t,traders.get(idOf(t.trader))?.name,byId))summary.append(el('p',line));if(t.wikiFacts?.source?.url)summary.append(external('Русская Wiki ↗',t.wikiFacts.source.url),el('p','Доступные сведения Wiki сверены отдельно от условий API.','wiki-source-note'));root.append(summary);}
  renderRequirements(root,t);if(t.gunsmith?.builds?.length)detailRoot.append(new GunsmithView(t,{mode,items:data.items}).root);const branchSelect=$('story-branch');if(branchSelect){const selector=el('div','','quest-branch-picker');selector.append(detailRoot.querySelector('.story-branch-label'),branchSelect);detailRoot.append(selector);}root=detailRoot;const s=section('Цели задания');s.id='quest-objectives-section';s.classList.add('quest-objectives');if(t.story)s.classList.add('story-objectives');const state=entry(t),branch=(t.storyBranches||[]).find(b=>b.id===state.storyBranch);
  const objectives=orderedObjectives(t,state.storyBranch,mode),gallery=questGalleryPlan(t,objectives);
  if(hasQuestRoadmap(t))renderTicketMap(root,t);root.append(s);const preferences=readStorage(viewKey())?.workspace?.[t.id]||{};objectiveWorkspace=new QuestObjectiveWorkspace({root:s,objectives,story:!!t.story,completed:()=>entry(t).objectives,preferences,savePreferences:next=>{const view=readStorage(viewKey())||{};writeStorage(viewKey(),{...view,workspace:{...view.workspace,[t.id]:next}});},onProgress:()=>renderHeader()});
  const completion=el('p','','objective-progress');completion.setAttribute('role','status');const updateCompletion=()=>{completion.textContent=`Выполнено ${objectives.filter(o=>entry(t).objectives.includes(o.id)).length} из ${objectives.length} целей. Отмеченные цели остаются в списке; подробности можно свернуть вручную.`;};updateCompletion();s.append(completion);
  for(const [index,o] of objectives.entries()){
    const row=el('div','','objective-row');row.dataset.objective=o.id;row.classList.toggle('is-done',state.objectives.includes(o.id));const check=el('input');check.type='checkbox';check.id='objective-'+index;check.checked=state.objectives.includes(o.id);check.onchange=()=>{const was=entry(t).objectives.includes(o.id),checked=check.checked;update(t,{objectives:setObjective(entry(t).objectives,o.id,checked)});objectiveWorkspace.changed(o.id,checked);updateCompletion();feedback.offerUndo(checked?'Цель отмечена':'Отметка снята',()=>{update(t,{objectives:setObjective(entry(t).objectives,o.id,was)});objectiveWorkspace?.changed(o.id,was);updateCompletion();});};
    const summary=el('div','','objective-content'),label=el('label',o.description,'objective-title');label.htmlFor=check.id;if(t.story){const titleLine=el('div','','story-objective-title');titleLine.append(el('span',String(index+1).padStart(2,'0'),'story-goal-number'),label);summary.append(titleLine);}else summary.append(label);if(o.optional)summary.append(el('span','Дополнительная цель','quest-tag'));const doneNote=el('span','Выполнено','objective-complete-note');doneNote.hidden=!check.checked;summary.append(doneNote);const body=el('div','','objective-details');body.id='objective-details-'+index;body.hidden=check.checked;check.setAttribute('aria-controls',body.id);summary.append(body);const meta=el('div','','objective-meta');if(o.count)meta.append(el('span','Количество: '+num(o.count)));if(o.foundInRaid)meta.append(el('span','Найдено в рейде','quest-tag'));body.append(meta);const conditions=objectiveConditions(o,list(data.skills),ruText);if(conditions.length){const ul=el('ul','','objective-conditions');for(const text of conditions)ul.append(el('li',text));body.append(ul);}if(o.branchLabels?.length)summary.insertBefore(el('p','Ветка: '+o.branchLabels.join(' / '),'objective-branch'),body);if(o.conditions?.length){const conditionsLabel=el('div','','objective-branch');for(const condition of o.conditions)conditionsLabel.append(el('p',typeof condition==='string'?condition:condition.description||condition.text||''));summary.insertBefore(conditionsLabel,body);}
    if(t.story)body.append(el('span',`Этап ${index+1}${o.phase==='terminal'?' · Терминал':''}`,'story-step-label'));for(const line of o.guide||objectiveGuide(o))body.append(el('p',line,'objective-guide'));
    const refs=itemReferences(o.items||o.item||o.questItem);if(refs.length){const wrap=el('div','','objective-items');for(const id of refs.slice(0,6))wrap.append(itemChip(id));body.append(wrap);if(refs.length>6){const details=el('details');details.append(el('summary',`Другие подходящие предметы (${refs.length-6})`));const more=el('div','','objective-items');for(const id of refs.slice(6))more.append(itemChip(id));details.append(more);body.append(details);}}
    const equipment=itemReferences([o.usingWeapon,o.usingWeaponMods,o.wearing]);if(equipment.length){const details=el('details');details.append(el('summary','Оружие и снаряжение по условию'));const wrap=el('div','','objective-items');for(const id of equipment)wrap.append(itemChip(id));details.append(wrap);body.append(details);}
    if(o.notWearing?.length){const details=el('details');details.append(el('summary','Запрещённое снаряжение'));const wrap=el('div','','objective-items');for(const item of itemReferences(o.notWearing))wrap.append(itemChip(item));details.append(wrap);body.append(details);}
    if(o.requiredKeys?.length)body.append(el('p','Ключи для этой цели:','objective-subheading'),keyGroups(o.requiredKeys));
    if(o.requiredItems?.length){const needed=el('div','','objective-items');body.append(el('p','Потребуется:'));for(const item of o.requiredItems){for(const ref of itemReferences(item.ids||item.item||item.id))needed.append(itemChip(ref,item.count||1));if(item.foundInRaid)needed.append(el('span','Найдено в рейде','quest-tag'));if((item.ids||[]).length>1)needed.append(el('span','Подходят альтернативные предметы из списка.'));}body.append(needed);}const photos=gallery.byObjective.get(o.id);if(photos?.length)body.append(galleryBlock(photos,{title:'Место выполнения',compact:true,association:'objective',contextTitle:t.name,carousel:gallery.frames}));
    if(o.markerItem)body.append(el('p','Устройство для установки:'),itemChip(o.markerItem));
    const mapIds=[...new Set([...(o.maps||[]).map(idOf),...(o.zones||[]).map(x=>idOf(x.map))])];const mapRows=el('div','','quest-map-links');const seen=new Set();for(const id of mapIds){const m=maps.get(id),alias=m&&mapAlias(m.normalizedName);if(alias&&!seen.has(alias)){seen.add(alias);const a=mapLink(id,t.id,o.noMapCoordinates?'Локация':'Места задания',o.noMapCoordinates?undefined:o.id);if(a&&o.noMapCoordinates){const url=new URL(a.href,location.href);url.searchParams.delete('quest');a.href=url.toString();}if(a)mapRows.append(a);}}body.append(mapRows);row.append(check,summary);objectiveWorkspace.register(o,row,body,check,doneNote);
  }objectiveWorkspace.refresh();
  questMap=new QuestMapView(t,{mode,index:extras.questMaps,objectives});root.append(questMap.root);
  if(gallery.general.length)root.append(galleryBlock(gallery.general,{title:'Где выполнять задание',contextTitle:t.name,carousel:gallery.frames}));
  if(t.neededKeys?.length){const keys=section('Нужные ключи');for(const group of t.neededKeys){keys.append(el('p',maps.get(idOf(group.map))?.name||'Локация'));keys.append(keyGroups(group.keys));}root.append(keys);}
  if(t.failConditions?.length){const fail=section('Условия провала');for(const f of t.failConditions){const task=byId.get(idOf(f.task));if(task)fail.append(taskButton(task,`Может провалить текущее задание при статусе: ${(f.status||[]).map(s=>QUEST_STATES[s]||s).join(' / ')}`));else fail.append(el('p',f.description&&!/^[a-f0-9]{24}$/.test(f.description)?f.description:'Есть отдельное условие провала. Проверьте его в игре.'));}root.append(fail);}
  const guides=section('Прохождение и материалы');guides.id='quest-materials-section';const own=el('p','Используйте цели выше как чек-лист. Подготовьте указанные предметы и ключи, затем откройте места задания на карте. Метки «Найдено в рейде» и особые условия снаряжения обязательны, если они указаны в цели.');guides.append(own);for(const line of t.storyGuide||[])if(hasQuestRoadmap(t)||!/схем/i.test(line))guides.append(el('p',line));const links=el('div','','guide-button-row');for(const source of t.storySources||[])links.append(external(source.label+' ↗',source.url));const help=data.guideLinks?.[t.id]?.tarkovHelpUrl;if(help)links.append(external('Прохождение на Tarkov.help ↗',help));if(t.wikiLink)links.append(external('Страница Wiki ↗',t.wikiLink));guides.append(links);root.append(guides);
}
function renderTicketMap(root,t){
 if(!hasQuestRoadmap(t))return;
 const panel=section('КАРТА КВЕСТА');panel.classList.add('ticket-map-panel');root.append(panel);
 roadmap=new TicketQuestMap(extras.ticketMap,{mode,stateId:t.id}).mount(panel);
}
function renderChain(root,t,{compact=false}={}){
  const before=el('div','','quest-chain-neighbors');if(!t.taskRequirements?.length)before.append(el('p','Предыдущие задания не указаны.','quest-chain-empty'));for(const r of t.taskRequirements||[]){const p=byId.get(idOf(r.task));if(p)before.append(chainCard(p,'previous','Нужен статус: '+(r.status||['complete']).map(s=>QUEST_STATES[s]||s).join(' / ')));}root.append(before);
  const after=el('div','','quest-chain-neighbors'),next=followingTasks(t,tasks);if(!next.length)after.append(el('p','Продолжение не указано.','quest-chain-empty'));for(const n of next)after.append(chainCard(n,'next'));root.append(after);
  if(t.relatedQuests?.length){const related=section('Связанные задания');related.classList.add('quest-chain-related');for(const link of t.relatedQuests){const target=byId.get(idOf(link.task));if(target)related.append(taskButton(target,link.label));}root.append(related);}
  const wiki=t.wikiFacts;if(wiki?.facts?.nextQuestNames?.length){const extra=section('Продолжение по русской Wiki');extra.append(el('p',wiki.facts.nextQuestNames.join('; ')+'.'),external('Источник связей ↗',wiki.source.url),el('p','Справочная версия Wiki может отличаться от связей текущего снимка API.','wiki-source-note'));root.append(extra);}
  const tree=section('Карта связей');tree.append(el('p','Соседние уровни цепочки: до двух шагов назад и вперёд. Нажмите задание, чтобы открыть его полные условия. Условия с несколькими ветками перечислены выше.'));const wrap=el('div','','quest-chain-wrap'),graph=el('div','','quest-chain');for(const [depth,nodes] of chainColumns(t,tasks,2)){const col=el('div','','chain-column');col.append(el('small',depth<0?`${Math.abs(depth)} шаг(а) до`:depth>0?`${depth} шаг(а) после`:'Выбрано'));for(const node of nodes){const b=action(node.name,()=>selectTask(node.id),'chain-node'+(depth===0?' current':''));b.append(el('small',`${traders.get(idOf(node.trader))?.name||''} · ${node.story?'Сюжет':(node.minPlayerLevel||1)+' ур.'}`));col.append(b);}graph.append(col);}wrap.append(graph);tree.append(wrap);if(compact){const details=el('details','','quest-chain-details');details.append(el('summary','Карта связей'),tree);root.append(details);}else root.append(tree);
}
function chainCard(task,direction,condition=''){
 const previous=direction==='previous',card=routeLink('',questHref(task.id,mode),'quest-chain-card quest-chain-'+direction);
 const label=el('span',previous?'Предыдущее задание':'Следующее задание','quest-chain-direction'),arrow=el('span',previous?'←':'→','quest-chain-arrow');arrow.setAttribute('aria-hidden','true');label.prepend(arrow);
 card.append(label,el('strong',task.name,'quest-chain-name'),el('span',`${traders.get(idOf(task.trader))?.name||'Основной сюжет'} · ${task.story?'Сюжет':(task.minPlayerLevel||1)+' ур.'}`,'quest-chain-meta'));
 if(condition)card.append(el('span',condition,'quest-chain-condition'));
 return card;
}
function rewardBucket(root,type){const bucket=el('section','','quest-reward-type');bucket.dataset.rewardType=type;bucket.append(el('h4',rewardLabels[type]));root.append(bucket);return bucket;}
function achievementCard(value){
 const achievement=resolveAchievement(value,data.achievements,mode),card=el('div','','quest-achievement-card');
 if(achievement)card.dataset.achievement=achievement.id;
 const icon=el('span','','quest-achievement-art');icon.setAttribute('aria-hidden','true');
 const trophy=document.createElementNS('http://www.w3.org/2000/svg','svg');trophy.setAttribute('viewBox','0 0 32 32');trophy.setAttribute('focusable','false');
 const path=document.createElementNS('http://www.w3.org/2000/svg','path');path.setAttribute('d','M10 4h12v7c0 5-3 8-6 8s-6-3-6-8V4Zm0 2H5v4c0 4 3 6 6 6M22 6h5v4c0 4-3 6-6 6M16 19v6m-6 3h12m-10-3h8');trophy.append(path);icon.append(trophy);
 const iconUrl=extras.rewardSupport?.achievements?.[achievement?.id]?.icon||achievement?.imageLink;
 if(iconUrl)icon.append(image(iconUrl,'','quest-achievement-icon'));
 const text=el('div','','quest-achievement-copy');text.append(el('span','Достижение','quest-achievement-label'),el('strong',achievement?.name||String(value),'quest-achievement-name'));
 if(achievement?.rarity)text.append(el('span',achievement.rarity,'quest-achievement-rarity'));
 card.append(icon,text);return card;
}
function renderRewardLines(root,lines,{icons=false}={}){
 for(const group of groupRewardLines(lines)){
  const bucket=rewardBucket(root,group.type);
  for(const line of group.lines){
   if(group.type==='achievements'){for(const name of rewardAchievementNames(line))bucket.append(achievementCard(name));if(/^(?:(?:Разблокирует|Открывает)\s+)?Достижение\s*(?::\s*[^.]+|[«"“][^»"”]+[»"”])\.?$/i.test(line))continue;}
   if(icons&&['items','buy','barter','craft'].includes(group.type)){
    const key=value=>String(value).normalize('NFKC').toLowerCase().replace(/ё/g,'е').replace(/[«»“”"']/g,'').replace(/\s+/g,' ').replace(/(^|\s)плату(?=\s)/g,'$1плата').trim();
    const quantity=line.match(/^(\d+)\s*[xх×]\s+(.+)$/i),text=key(quantity?.[2]||line);
    const candidates=list(data.items).filter(item=>item.name&&key(item.name).length>7&&(quantity?key(item.name)===text:text.includes(key(item.name)))).sort((a,b)=>b.name.length-a.name.length);
    if(candidates[0]){bucket.append(itemChip(candidates[0].id,quantity?Number(quantity[1]):1));if(quantity)continue;}
   }
   // Keep source conditions, including conditional endings and mixed rewards.
   bucket.append(el('p',icons&&group.type==='experience'?'Опыт: '+line:line,'quest-reward-description'));
  }
 }
}
function renderWikiRewardFacts(root,t){
 const record=t.wikiFacts,f=record.facts,s=section('Награды русской Wiki');
 if(f.rewardXp!=null)rewardBucket(s,'experience').append(el('p',`Опыт: +${num(f.rewardXp)}.`));
 if(f.rewardReputations?.length){const bucket=rewardBucket(s,'reputation');for(const r of f.rewardReputations)bucket.append(el('p',`${r.traderName}: ${r.amount>=0?'+':''}${num(r.amount)} репутации.`));}
 const currency={RUB:'₽',USD:'$',EUR:'€'};
 if(f.rewardMoney?.length||f.rewardItems?.length){const bucket=rewardBucket(s,'items');
  for(const r of f.rewardMoney||[])bucket.append(el('p',`${num(r.amount)} ${currency[r.currency]||r.currency}${r.intelligenceLevel?' — Разведцентр '+r.intelligenceLevel+' уровня':' — базовая сумма'}.`));
  for(const r of f.rewardItems||[])bucket.append(el('p',`${num(r.count)} × ${r.name}`));
 }
 for(const type of ['buy','barter','craft']){const rows=(f.rewardUnlocks||[]).filter(r=>r.type===type);if(!rows.length)continue;const bucket=rewardBucket(s,type);for(const r of rows)bucket.append(el('p',`${r.itemName}${r.traderName?' · '+r.traderName:''}${r.loyaltyLevel?' · лояльность '+r.loyaltyLevel:''}.`));}
 s.append(external('Источник наград ↗',record.source.url));
 if(record.source.rewardCompleteness!=='observed-block'||!record.source.rewardBlockClosed)s.append(el('p','Доступна только часть сведений о награде. Отсутствующие значения не считаются нулём.','wiki-source-note'));
 root.append(s);
}
function renderRewardGroup(root,rewards,title,experience,taskId){
 const s=section(title),trade=rewardTradeUnlocks(rewards,taskId,extras.rewardSupport?.modes?.[mode]?.barters);
 if(experience!==undefined)rewardBucket(s,'experience').append(el('p',experience===null?'Опыт: значение не указано источником':`Опыт: +${num(experience)} опыта`,'quest-callout'));
 if(rewards?.items?.length){const grid=el('div','','rewards-grid');for(const r of rewards.items)grid.append(itemChip(r.item,r.count));rewardBucket(s,'items').append(grid);}
 if(rewards?.traderStanding?.length){const bucket=rewardBucket(s,'reputation');for(const r of rewards.traderStanding)bucket.append(el('p',`${traders.get(idOf(r.trader))?.name||'Торговец'}: ${r.standing>=0?'+':''}${num(r.standing)} репутации`));}
 for(const type of ['buy','barter'])if(trade[type].length){const bucket=rewardBucket(s,type);for(const r of trade[type]){const offer=el('div','','quest-reward-unlock');offer.append(itemChip(r.item,r.count||1),el('p',`${traders.get(idOf(r.trader))?.name||'Торговец'}${r.level?', уровень лояльности '+r.level:''}`));bucket.append(offer);}}
 if(rewards?.craftUnlock?.length){const bucket=rewardBucket(s,'craft');for(const r of rewards.craftUnlock){const craft=el('div','','quest-reward-unlock');craft.append(itemChip(r.item,r.count||1),el('p',`${list(data.hideout).find(x=>x.id===idOf(r.station))?.name||'Станция убежища'}${r.level?', уровень '+r.level:''}`));bucket.append(craft);}}
 if(rewards?.skillLevelReward?.length){const bucket=rewardBucket(s,'skills');for(const r of rewards.skillLevelReward)bucket.append(el('p',`${list(data.skills).find(s=>s.id===idOf(r.skill))?.name||r.skill}: +${num(r.level)}`));}
 if(rewards?.achievement?.length){const bucket=rewardBucket(s,'achievements');for(const value of rewards.achievement)bucket.append(achievementCard(value));}
 for(const [field,type,lookup] of [['traderUnlock','traders',traders],['traderDialogueUnlock','dialogue',traders],['locationUnlock','locations',maps]])if(rewards?.[field]?.length){const bucket=rewardBucket(s,type);for(const value of rewards[field])bucket.append(el('p',lookup.get(idOf(value))?.name||idOf(value)));}
 if(rewards?.customization?.length){const bucket=rewardBucket(s,'customization');for(const c of rewards.customization)bucket.append(el('p',c.name||c.id||c));}
 if(!s.querySelector('.quest-reward-type'))s.append(el('p','Отдельные награды не указаны.'));root.append(s);
}
function renderRewards(root,t){
 if(t.questContent?.rewards?.policy==='completion'){
  const panel=el('div','','quest-source-rewards');renderRewardLines(panel,t.questContent.rewards.lines,{icons:true});
  if(!t.questContent.rewards.lines.length)panel.append(el('p','Награды не указаны в источнике.'));
  root.append(panel);
  if(t.startRewards&&Object.values(t.startRewards).some(v=>v?.length))renderRewardGroup(root,t.startRewards,'Выдаётся при старте');
  if(t.failureOutcome&&Object.values(t.failureOutcome).some(v=>v?.length))renderRewardGroup(root,t.failureOutcome,'При провале');
  return;
 }
 if(t.catalogRewardLines){const s=el('div','','quest-catalog-rewards');renderRewardLines(s,t.catalogRewardLines);if(!t.catalogRewardLines.length)s.append(el('p','Награды не указаны в источнике.'));root.append(s);return;}
 if(t.wikiFacts?.source?.rewardFactsObserved)renderWikiRewardFacts(root,t);
 const wiki=t.media?.wikiRewards;
 if(wiki){
  renderRewardGroup(root,wiki.finishRewards,'Награды по Вики',wiki.experience);
  root.append(external('Источник наград — русская Вики ↗',wiki.sourceUrl),el('p',wiki.warning||'Версия Вики может отличаться от текущего баланса игры.','guide-footer-note'));
  if(wiki.intelligenceCenterBonuses?.length)for(const bonus of wiki.intelligenceCenterBonuses)root.append(el('p',`С разведцентром ${bonus.level} уровня: ${num(bonus.amount)} евро.`));
 }else if(t.story&&t.storyRewards?.length){
  const sectionNode=section('Награды сюжетной главы');renderRewardLines(sectionNode,t.storyRewards);
  if(t.storyRewardSource)sectionNode.append(external('Источник наград — Вики ↗',t.storyRewardSource));
  if(t.storyRewardCoverage)sectionNode.append(el('p',t.storyRewardCoverage,'guide-footer-note'));root.append(sectionNode);
  for(const branch of t.storyBranches||[]){
   if(entry(t).storyBranch&&entry(t).storyBranch!==branch.id)continue;
   const group=el('details','','quest-branch-rewards');group.append(el('summary',branch.name));renderRewardLines(group,branch.rewardsByMode?.[mode]||branch.rewards||[]);
   if(branch.rewardsSource)group.append(external('Источник наград варианта ↗',branch.rewardsSource));root.append(group);
  }
 }else{
  renderRewardGroup(root,t.finishRewards,t.rewardSource?'Награды события':'За выполнение',t.experience,t.id);
  if(t.rewardSource)root.append(external('Источник наград — '+t.rewardSource.label+' ↗',t.rewardSource.url));

 }
 if(t.startRewards&&Object.values(t.startRewards).some(v=>v?.length))renderRewardGroup(root,t.startRewards,'Выдаётся при старте');
 if(t.failureOutcome&&Object.values(t.failureOutcome).some(v=>v?.length))renderRewardGroup(root,t.failureOutcome,'При провале');
 root.append(el('p','Бонусы убежища, издания и временных событий могут изменить итоговую награду.','guide-footer-note'));
}
function setData(bundle){
 bundle=enrichQuests(bundle,extras);
  if(!Array.isArray(bundle.tasks)||!bundle.tasks.length||!bundle.tasks.every(t=>t.id&&typeof t.name==='string'&&Array.isArray(t.objectives)))throw new Error('API вернул неполный список заданий');
  data=bundle;tasks=bundle.tasks;choices=questChoiceIndex(tasks,extras.choices,mode);byId=new Map(tasks.map(t=>[t.id,t]));traders=new Map(list(bundle.traders).map(t=>[t.id,t]));maps=new Map(list(bundle.maps).map(m=>[m.id,m]));progress=sanitizeQuestProgress({...readStorage(progressKey()),mode},tasks);
  if(route.kind==='catalog'&&!['hub','all','story','archive'].includes(route.section)&&!traders.has(route.section))route.section='all';
  $('quest-mode').value=mode;$('header-quest-mode').value=mode;$('player-level').value=progress.level;$('player-faction').value=progress.faction;
  $('quest-map').replaceChildren();const all=el('option','Все локации');all.value='all';$('quest-map').append(all);const seen=new Set();for(const m of maps.values()){const alias=mapAlias(m.normalizedName);if(seen.has(alias))continue;seen.add(alias);const o=el('option',m.name);o.value=alias;$('quest-map').append(o);}
  $('quest-data-date').textContent=`Данные: ${new Date(bundle.meta.fetchedAt).toLocaleString('ru-RU')}. ${sectionTasks(tasks,'all').length} квестов · ${mode==='pve'?'PVE':'PVP'}.`;if(bundle.wikiCoverage){$('quest-data-date').textContent+=` Wiki: ${bundle.wikiCoverage.exactMatches} из ${bundle.wikiCoverage.attempted} страниц.`;}
  restoreView();renderTraders();renderRoute();
}
async function loadMode(){questMap?.destroy();questMap=null;const token=++request;for(const n of [...$('quest-layout').querySelectorAll('input,select,button'),...$('quest-filters-dialog').querySelectorAll('input,select,button')])n.disabled=true;$('quest-detail').replaceChildren(empty('Загружаем задания…'));selected=null;filterTrader='all';const [bundle,more]=await Promise.all([cached('quests-'+mode).then(x=>x||json('data/guide/quests-'+mode+'.json')),questExtras()]);extras=more;if(token!==request)return;setData(bundle);for(const n of [...$('quest-layout').querySelectorAll('input,select,button'),...$('quest-filters-dialog').querySelectorAll('input,select,button')])n.disabled=false;}
async function refresh(){const target=mode,token=request,baseline=data;const b=$('quest-refresh');b.disabled=true;b.textContent='Обновление…';const abort=new AbortController();const timeout=setTimeout(()=>abort.abort(),25000);
  try{const [fresh,rawTraders]=await Promise.all([apiDataset('tasks',target,abort.signal),apiDataset('traders',target,abort.signal)]);const bundle={...baseline,tasks:list(fresh.tasks),questItems:fresh.questItems,achievements:fresh.achievements,traders:list(rawTraders.traders||rawTraders),meta:{...baseline.meta,fetchedAt:new Date().toISOString(),source:'https://json.tarkov.dev/'+target+'/tasks'}};if(!bundle.tasks.length)throw new Error('Empty');await cached('quests-'+target,bundle);if(token===request)setData(bundle);toast('Квесты обновлены из API');}catch{toast('API сейчас недоступен. Сохранённые задания и прогресс продолжают работать.');}finally{clearTimeout(timeout);b.disabled=false;b.textContent='Обновить из API ↻';}}
function wire(){
  $('quest-mode').onchange=()=>{feedback.clearUndo();mode=$('quest-mode').value;writeStorage('kord-atlas-game-mode',mode);const url=new URL(location.href);url.searchParams.set('mode',mode);history.replaceState(null,'',canonicalURL(url));updateParams();return loadMode().catch(fail);};
  $('header-quest-mode').onchange=()=>{$('quest-mode').value=$('header-quest-mode').value;return $('quest-mode').onchange();};
  $('player-level').onchange=()=>{progress.level=Math.max(1,Math.min(100,Math.floor(Number($('player-level').value)||1)));$('player-level').value=progress.level;save();renderList();renderDetail();};
  $('player-faction').onchange=()=>{progress.faction=$('player-faction').value;save();renderList();renderDetail();};
  $('quest-search').oninput=()=>{limit=70;renderList();};for(const id of ['quest-map','quest-status','quest-flag','quest-sort'])$(id).onchange=()=>{limit=70;renderList();};
  $('quest-reset').onclick=()=>{$('quest-search').value='';for(const id of ['quest-map','quest-status','quest-flag'])$(id).value='all';$('quest-sort').value='level';limit=70;renderTraders();renderList();};
  $('quest-more').onclick=()=>{limit+=100;renderList();};$('quest-mobile-filters').onclick=()=>{const layout=$('quest-layout'),dialog=$('quest-filters-dialog');layout.classList.add('show-filters');$('quest-mobile-filters').setAttribute('aria-expanded','true');if(innerWidth<=700){dialog.append(document.querySelector('.quest-toolbar'));dialog.showModal();$('quest-search').focus();}};$('quest-filters-close').onclick=closeFilters;$('quest-filters-dialog').addEventListener('cancel',e=>{e.preventDefault();closeFilters();});window.addEventListener('resize',()=>{if(innerWidth>700&&$('quest-filters-dialog').open)closeFilters();});$('quest-refresh').onclick=refresh;
  $('quest-export').onclick=()=>download({...progress,mode,exportedAt:new Date().toISOString()},`kord-quests-${mode}-${new Date().toISOString().slice(0,10)}.json`);
  $('quest-import').onclick=()=>$('quest-import-file').click();$('quest-import-file').onchange=async()=>{try{const file=$('quest-import-file').files?.[0];if(!file)return;if(file.size>5e6)throw new Error('large');const raw=JSON.parse(await file.text());if(!raw.quests||raw.version!==1)throw new Error('format');if(raw.mode&&raw.mode!==mode){toast('Это прогресс другого режима. Сначала переключите игровой режим.');return;}const clean=sanitizeQuestProgress({...raw,mode},tasks);progress={...clean,quests:{...progress.quests,...clean.quests}};save();$('player-level').value=progress.level;$('player-faction').value=progress.faction;renderTraders();renderList();renderDetail();toast('Прогресс объединён');}catch{toast('Не удалось прочитать файл прогресса. Нужен JSON, скачанный из этого раздела.');}finally{$('quest-import-file').value='';}};
  $('guide-sources').onclick=()=>$('guide-sources-dialog').showModal();$('guide-sources-close').onclick=()=>$('guide-sources-dialog').close();
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&$('quest-layout').classList.contains('show-filters'))closeFilters();if(e.key==='/'&&!e.target.closest('input,textarea,select,dialog')&&route.kind==='catalog'){e.preventDefault();if(innerWidth<=700)$('quest-mobile-filters').onclick();$('quest-search').focus();}});
  window.addEventListener('wiki:state',e=>{if(e.detail.key===progressKey()&&data){progress=sanitizeQuestProgress({...readStorage(progressKey()),mode},tasks);renderTraders();renderList();renderDetail();}});
}
function fail(){questMap?.destroy();questMap=null;$('quest-layout').dataset.view='quest';const root=$('quest-detail');root.replaceChildren(empty('Не удалось загрузить справочник. Проверьте соединение.'),action('Повторить',()=>loadMode().catch(fail)));}
window.addEventListener('popstate',()=>{updateParams();route=catalogRoute(location.href);const requested=resolveGameMode(params.get('mode'),readStorage('kord-atlas-game-mode'));if(requested!==mode){mode=requested;return loadMode().catch(fail);}restoreView();renderRoute();});
$('quest-layout').dataset.view=route.kind==='quest'?'quest':'hub';wire();loadMode().catch(fail);

function renderStory(root,t){if(!t.story)return;const story=section('Сюжетная глава');story.classList.add('story-briefing');if(t.description&&!t.questContent?.briefing?.paragraphs?.length)story.append(el('p',t.description));for(const group of t.guideSections||[]){const box=el('details','','story-guide-group');box.append(el('summary',group.title));for(const line of group.paragraphs||[])box.append(el('p',line));if(group.sourceUrl)box.append(external('Источник этапа ↗',group.sourceUrl));story.append(box);}
 if(t.storyBranches?.length){const label=el('label','Вариант прохождения','story-branch-label'),select=el('select');select.id='story-branch';label.htmlFor=select.id;const all=el('option','Все варианты');all.value='';select.append(all);for(const branch of t.storyBranches){const option=el('option',branch.name);option.value=branch.id;select.append(option);}select.value=entry(t).storyBranch||'';select.onchange=()=>{update(t,{storyBranch:select.value});renderDetail();};story.append(label,select);const branch=t.storyBranches.find(b=>b.id===select.value);if(branch)for(const line of branch.conditions||[])story.append(el('p',line));}
 for(const choice of t.storyChoices||[]){const group=el('details');group.append(el('summary',choice.name));for(const option of choice.options||[]){group.append(el('h4',option.name),el('p',option.description));for(const line of option.rewards||[])group.append(el('p',line));}story.append(group);}
 if(t.storyCoverageNote)story.append(el('p',t.storyCoverageNote,'quest-callout warning'));for(const note of t.storyDataNotes||[])story.append(el('p',note,'guide-footer-note'));root.append(story);}
