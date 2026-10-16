import {assemblyFrames} from './gunsmith-model.mjs';

export const QUEST_TABS={};
export const hasQuestRoadmap=task=>task?.id==='68da33fe00868edcb6025ac4'||task?.normalizedName==='the-ticket';
export const questTabs=()=>QUEST_TABS;
export const resolveQuestTab=()=> 'objectives';

/** An original screenshot can have a thumbnail, a translation and cache revisions. */
export function questMediaKey(frame){
  const source=frame?.originalUrl||frame?.fullUrl||frame?.url;
  if(!source)return '';
  try{const url=new URL(source,'https://wiki.local/');return url.origin+url.pathname.replace(/\/revision\/.*$/,'');}
  catch{return String(source);}
}

/** Place every photograph once, using only explicit objective associations. */
export function questGalleryPlan(task,objectives=task.objectives||[]){
  const bySource=new Map(),known=new Set((task.objectives||[]).map(o=>o.id));
  const add=(frame,objectiveId)=>{
    const key=questMediaKey(frame);if(!key)return;
    const previous=bySource.get(key),ids=[...new Set([...(previous?.objectiveIds||[]),...(frame.objectiveIds||[]),...(objectiveId?[objectiveId]:[])])];
    bySource.set(key,{...frame,...previous,url:previous?.url||frame.url||frame.fullUrl,fullUrl:previous?.fullUrl||frame.fullUrl||frame.url,
      sourceUrl:previous?.sourceUrl||frame.sourceUrl||frame.source,objectiveIds:ids});
  };
  for(const frame of [...(task.media?.gallery||[]),...(task.gallery||[])])add(frame);
  for(const objective of task.objectives||[])for(const frame of objective.media||[])add(frame,objective.id);
  const reserved=new Set(assemblyFrames(task.gunsmith).map(questMediaKey));
  const byObjective=new Map(objectives.map(o=>[o.id,[]])),general=[],archived=[],archive=new Map((task.galleryArchiveRules||[]).flatMap(rule=>(rule.urls||[]).map(url=>[questMediaKey({url}),rule.reason])));
  for(const [key,frame] of bySource){
    // Weapon examples already have their three-view presentation inside the task.
    if(reserved.has(key))continue;
    if(archive.has(key)){archived.push({...frame,archived:true,archiveReason:archive.get(key)});continue;}
    const target=objectives.find(o=>frame.objectiveIds.includes(o.id));
    if(target)byObjective.get(target.id).push(frame);
    else if(!frame.objectiveIds.some(id=>known.has(id)))general.push(frame);
  }
  const frames=[...byObjective.values()].flat().concat(general);
  return {byObjective,general,frames,archived};
}
