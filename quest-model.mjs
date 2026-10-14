import {normalize} from './model.mjs';

export const QUEST_STATES={notstarted:'Не начат',active:'В процессе',complete:'Выполнен',failed:'Провален'};
export const idOf=value=>typeof value==='string'?value:value?.id;
export const list=value=>Array.isArray(value)?value:Object.values(value||{});
export const mapAlias=name=>({'night-factory':'factory','ground-zero-21':'ground-zero','ground-zero-tutorial':'ground-zero','the-lab-dark':'the-lab'}[name]||name);
export function taskMaps(task,maps=[]){
  const ids=new Set([idOf(task.map),...(task.objectives||[]).flatMap(o=>[...(o.maps||[]).map(idOf),...(o.zones||[]).map(z=>idOf(z.map))])].filter(Boolean));
  return maps.filter(m=>ids.has(m.id));
}
export function sanitizeQuestProgress(raw,tasks){
  const valid=new Map(tasks.map(t=>[t.id,t]));const out={version:1,mode:['regular','pve'].includes(raw?.mode)?raw.mode:undefined,level:Math.max(1,Math.min(100,Math.floor(Number(raw?.level)||1))),faction:['BEAR','USEC'].includes(raw?.faction)?raw.faction:'any',quests:{}};
  if(!raw?.quests||typeof raw.quests!=='object'||Array.isArray(raw.quests))return out;
  for(const [id,entry] of Object.entries(raw.quests)){
    if(!valid.has(id)||!entry||typeof entry!=='object')continue;
    const objectives=new Set((valid.get(id).objectives||[]).map(o=>o.id));
    out.quests[id]={storyBranch:(valid.get(id).storyBranches||[]).some(b=>b.id===entry.storyBranch)?entry.storyBranch:'',status:Object.hasOwn(QUEST_STATES,entry.status)?entry.status:'notstarted',favorite:entry.favorite===true,note:typeof entry.note==='string'?entry.note.slice(0,3000):'',objectives:Array.isArray(entry.objectives)?[...new Set(entry.objectives.filter(x=>objectives.has(x)))]:[]};
  }return out;
}
export const questState=(task,progress)=>progress.quests?.[task.id]?.status||'notstarted';
export function requirementStatus(requirement,progress){
  const status=progress.quests?.[idOf(requirement.task)]?.status||'notstarted';
  const accepted=requirement.status?.length?requirement.status:['complete'];
  return accepted.some(s=>s===status||(s==='started'&&status==='active')||(s==='finish'&&status==='complete')||(s==='availableForFinish'&&status==='active'&&requirement.status.includes('started')));
}
export function availability(task,progress){
  const level=Number(task.minPlayerLevel)||1;
  const missing=(task.taskRequirements||[]).filter(r=>!requirementStatus(r,progress));
  const faction=task.factionName&&task.factionName!=='Any'&&task.factionName!=='AnyPmc'&&progress.faction!=='any'&&task.factionName.toUpperCase()!==progress.faction;
  const external=Boolean(task.traderRequirements?.length||task.otherRequirements?.length||task.requiredPrestige||task.availableDelaySecondsMin||task.storyRequirements?.length||task.catalogRequirements?.length||task.catalogLevelUnknown||task.catalogModeNotes?.[progress.mode]);
  const modeUnavailable=task.catalogUnavailableModes?.includes(progress.mode);
  return {available:progress.level>=level&&!missing.length&&!faction&&!modeUnavailable,modeUnavailable,missing,level,faction,external};
}
export function followingTasks(task,tasks){return tasks.filter(t=>(t.taskRequirements||[]).some(r=>idOf(r.task)===task.id));}
export function filterQuests(tasks,{query='',trader='all',map='all',status='all',flag='all',sort='level',progress,maps=[]}){
  const words=normalize(query).split(/\s+/).filter(Boolean);
  const results=tasks.filter(t=>{
    if(trader!=='all'&&idOf(t.trader)!==trader)return false;
    if(map!=='all'&&!taskMaps(t,maps).some(m=>mapAlias(m.normalizedName)===map))return false;
    const state=questState(t,progress);
    if(status==='available'&&(state!=='notstarted'||!availability(t,progress).available))return false;
    if(status==='locked'&&(state!=='notstarted'||availability(t,progress).available))return false;
    if(status==='remaining'&&state==='complete')return false;
    if(Object.hasOwn(QUEST_STATES,status)&&state!==status)return false;
    if(flag==='favorite'&&!progress.quests?.[t.id]?.favorite)return false;
    if(flag==='kappa'&&!t.kappaRequired)return false;if(flag==='lightkeeper'&&!t.lightkeeperRequired)return false;
    const text=normalize([t.name,t.normalizedName,...(t.objectives||[]).map(o=>o.description)].join(' '));return words.every(w=>text.includes(w));
  });
  return results.sort((a,b)=>sort==='name'?a.name.localeCompare(b.name,'ru'):sort==='xp'?(b.experience||0)-(a.experience||0):(a.minPlayerLevel||1)-(b.minPlayerLevel||1)||a.name.localeCompare(b.name,'ru'));
}
export function chainColumns(task,tasks,depth=2){
  const byId=new Map(tasks.map(t=>[t.id,t])),seen=new Set([task.id]),columns=new Map([[0,[task]]]);
  let previous=[task],next=[task];
  for(let n=1;n<=depth;n++){
    previous=[...new Set(previous.flatMap(t=>(t.taskRequirements||[]).map(r=>idOf(r.task))))].map(id=>byId.get(id)).filter(t=>t&&!seen.has(t.id));previous.forEach(t=>seen.add(t.id));if(previous.length)columns.set(-n,previous);
    next=[...new Map(next.flatMap(t=>followingTasks(t,tasks)).filter(t=>!seen.has(t.id)).map(t=>[t.id,t])).values()];next.forEach(t=>seen.add(t.id));if(next.length)columns.set(n,next);
  }return [...columns].sort(([a],[b])=>a-b);
}

export function requiredQuestItems(tasks,progress){
  const result=new Map();
  for(const t of tasks){if(t.catalogSection==='supplementary')continue;if(questState(t,progress)==='complete')continue;
    for(const o of t.objectives||[]){if(!['giveItem','findItem'].includes(o.type)||progress.quests?.[t.id]?.objectives?.includes(o.id))continue;
      const ids=(o.items||[o.item]).map(idOf).filter(Boolean);if(ids.length!==1)continue;
      const id=ids[0],entry=result.get(id)||{id,count:0,tasks:[],foundInRaid:false};
      // Find/hand-over often describe the same items: count each task once at its maximum.
      const existing=entry.tasks.find(x=>x.id===t.id);const count=Number(o.count)||1;
      if(existing){entry.count+=Math.max(0,count-existing.count);existing.count=Math.max(existing.count,count);}else{entry.count+=count;entry.tasks.push({id:t.id,name:t.name,count});}
      entry.foundInRaid ||= o.foundInRaid===true;result.set(id,entry);
    }
  }return [...result.values()];
}
