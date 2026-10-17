import {siteHref} from './site-routes.mjs?v=page-content-20261017';
export function questMapPlan(task,mode,index,objectives=task.objectives||[]) {
 const valid=new Set(objectives.map(o=>o.id));
 return (index?.modes?.[mode]?.[task.id]||[]).map(map=>{
  const points=map.renderer==='raid'&&task.story?map.points.filter(p=>!p.objectiveId||valid.has(p.objectiveId)):map.points;
  return {...map,points,count:points.length};
 }).filter(map=>map.count>0);
}
export function questMapURL(taskId,mode,map,objective='',embed=true) {
 const p=new URLSearchParams({quest:taskId,mode,map:map.renderer==='source'?map.sourceKey:map.id});
 if(map.renderer==='source')return siteHref('quest-map-source.html?'+p);
 if(embed)p.set('embed','quest');p.set('artwork','source');
 // An embedded map always contains the entire quest. Focus travels by message.
 if(!embed&&objective&&map.points.some(p=>p.objectiveId===objective))p.set('objective',objective);
 return siteHref('raid.html?'+p);
}
export function sourcePoint(point,asset) {return [-point.y*asset.height/100,point.x*asset.width/100];}

export function questMapForLocation(plans,locationId,objectiveId){
 const candidates=(plans||[]).filter(map=>map.id===locationId||map.locationId===locationId);
 return candidates.find(map=>objectiveId&&map.points.some(p=>p.objectiveId===objectiveId||p.objectiveIds?.includes(objectiveId)))||candidates[0];
}

export function questMapFocusIndexes(map,objectiveId,objectives=[]){
 if(!objectiveId)return [];
 const exact=map.points.flatMap((point,index)=>point.objectiveId===objectiveId||point.objectiveIds?.includes(objectiveId)?[index]:[]);if(exact.length)return exact;
 const objective=objectives.find(o=>o.id===objectiveId);
 const key=value=>String(value||'').normalize('NFKC').toLowerCase().replace(/ё/g,'е').replace(/[\s.,«»"“”]+/g,' ').trim();
 const label=key(objective?.description),labels=label?map.points.flatMap((point,index)=>key(point.name)===label?[index]:[]):[];
 return labels.length?labels:map.points.length===1?[0]:[];
}

/** Различает несколько схем одной локации и выбирает схему нужной цели. */
export const questMapKey=map=>map.renderer+":"+(map.sourceKey||map.id);
export function questMapForObjective(maps,mapId,objectiveId=""){
 const matches=maps.filter(map=>map.id===mapId||map.locationId===mapId);
 return matches.find(map=>objectiveId&&map.points.some(point=>point.objectiveId===objectiveId||point.objectiveIds?.includes(objectiveId)))||matches[0];
}
