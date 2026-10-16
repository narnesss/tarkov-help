import {siteHref,pageParams} from './site-routes.mjs?v=release-20261016';
import {idOf,questState} from './quest-model.mjs';

export function questHref(id,mode='pve',tab='objectives') {
  return siteHref('quest.html?'+new URLSearchParams({quest:id,mode}));
}
export function catalogHref(section='hub',mode='pve',filters={}) {
  const query=new URLSearchParams({mode});
  if(section==='story'||section==='all'||section==='archive')query.set('section',section);
  else if(section!=='hub')query.set('trader',section);
  for(const [key,value] of Object.entries(filters))if(value&&value!=='all'&&!(key==='sort'&&value==='level'))query.set(key,value);
  return siteHref('quests.html?'+query);
}
export function catalogRoute(url) {
  const u={searchParams:pageParams(url)};
  if(u.searchParams.has('quest'))return {kind:'quest',id:u.searchParams.get('quest'),tab:u.searchParams.get('tab')||'objectives'};
  const section=u.searchParams.get('trader')||u.searchParams.get('section')||'hub';
  return {kind:'catalog',section};
}
export function sectionTasks(tasks,section) {
  if(section==='archive')return tasks.filter(t=>t.catalogSection==='supplementary');
  tasks=tasks.filter(t=>t.catalogSection!=='supplementary');
  if(section==='story')return tasks.filter(t=>t.story);
  if(section==='all'||section==='hub')return tasks;
  return tasks.filter(t=>!t.story&&idOf(t.trader)===section);
}
export function catalogGroups(tasks,traders,progress) {
  return [{id:'story',name:'Основной сюжет'},...traders.filter(t=>t.id!=='story')]
    .map(tr=>{const own=sectionTasks(tasks,tr.id);return {...tr,total:own.length,done:own.filter(t=>questState(t,progress)==='complete').length};})
    .filter(tr=>tr.total>0);
}
export function questPreview(task,mode='pve') {
  const objectives=(task.objectives||[]).filter(o=>!o.modes||o.modes.includes(mode));
  return {objectives:objectives.slice(0,2).map(o=>o.description),more:Math.max(0,objectives.length-2),total:objectives.length};
}
