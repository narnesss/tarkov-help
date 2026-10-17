import {idOf} from './quest-model.mjs?v=page-content-20261017';

/** Mutual options and directional failure effects are different relationships. */
export function questChoiceIndex(tasks,reference={},mode='pve') {
 const byId=new Map(tasks.map(t=>[t.id,t])),index=new Map(tasks.map(t=>[t.id,{alternatives:new Set(),failedBy:new Set(),fails:new Set(),sources:new Set(),branches:[],notes:[]}])) ;
 for(const task of tasks)for(const condition of task.failConditions||[]){
  const other=idOf(condition.task);
  if(!other||other===task.id||!byId.has(other)||condition.optional||condition.type&&condition.type!=='taskStatus'||(!Array.isArray(condition.status)||!condition.status.includes('complete')))continue;
  index.get(task.id).failedBy.add(other);index.get(other).fails.add(task.id);
 }
 for(const [id,entry] of index)for(const other of entry.failedBy)if(entry.fails.has(other))entry.alternatives.add(other);
 for(const group of reference.groups||[]){
  if(group.modes&&!group.modes.includes(mode))continue;
  const options=group.options.map(ids=>ids.filter(id=>byId.has(id))).filter(ids=>ids.length);
  if(options.length<2)continue;
  for(const ids of options)for(const id of ids){const entry=index.get(id);
   for(const other of options)if(other!==ids)for(const target of other)entry.alternatives.add(target);
   for(const source of group.sources||[])entry.sources.add(source);
  }
 }
 for(const branch of reference.branchNotices||[])for(const id of branch.questIds||[])if(index.has(id))index.get(id).branches.push(branch);
 for(const note of reference.notes||[])for(const id of note.questIds||[])if(index.has(id)&&(!note.questId||byId.has(note.questId)))index.get(id).notes.push(note);
 return index;
}

/** Keep every real variant link while presenting repeated names only once. */
export function questChoicePlan(task,index,tasks) {
 const entry=index.get(task.id);if(!entry)return null;
 const byId=new Map(tasks.map(t=>[t.id,t])),pack=ids=>{
  const groups=new Map();for(const id of ids){const target=byId.get(id);if(!target)continue;
   const key=target.name+'|'+idOf(target.trader);if(!groups.has(key))groups.set(key,{name:target.name,trader:idOf(target.trader),tasks:[]});groups.get(key).tasks.push(target);
  }return [...groups.values()];
 };
 const alternatives=pack(entry.alternatives),failedBy=pack([...entry.failedBy].filter(id=>!entry.alternatives.has(id))),fails=pack([...entry.fails].filter(id=>!entry.alternatives.has(id)));
 if(!alternatives.length&&!failedBy.length&&!fails.length&&!entry.branches.length)return null;
 return {alternatives,failedBy,fails,branches:entry.branches,notes:entry.notes,sources:[...entry.sources]};
}
