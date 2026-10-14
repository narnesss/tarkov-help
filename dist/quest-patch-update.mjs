/** Add verified patch quests after the catalog overlay without resetting existing identities. */
export function applyQuestPatch(bundle,patch) {
 const records=patch?.modes?.[bundle.meta?.mode]?.tasks;
 if(!records?.length)return bundle;
 const tasks=new Map(bundle.tasks.map(task=>[task.id,task]));
 const aliases=new Map();
 for(const record of records){
  // Keep the saved local ID if a future API finally supplies this omitted quest.
  if(record.id.startsWith('patch-1150-')&&record.normalizedName)for(const task of tasks.values()){
   if(task.id!==record.id&&task.normalizedName===record.normalizedName){aliases.set(task.id,record.id);tasks.delete(task.id);}
  }
  const existing=tasks.get(record.id);
  if(!existing&&!record.objectives)continue;
  tasks.set(record.id,{...existing,...record});
 }
 const remap=value=>typeof value==='string'?(aliases.get(value)||value):value?.id?{...value,id:aliases.get(value.id)||value.id}:value;
 for(const [id,task] of tasks)if(aliases.size)tasks.set(id,{...task,...Object.fromEntries(['taskRequirements','failConditions'].filter(key=>task[key]).map(key=>[key,task[key].map(link=>({...link,task:remap(link.task)}))]))});
 return {...bundle,tasks:[...tasks.values()],questPatch:{version:patch.patch,sourceUrl:patch.sourceUrl,added:records.filter(t=>!bundle.tasks.some(old=>old.id===t.id)).length}};
}
