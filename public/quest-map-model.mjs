import {siteHref} from './site-routes.mjs';
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

export function questMapFocusIndexes(map,objectiveId,objectives=[]){
 if(!objectiveId)return [];
 const exact=map.points.flatMap((point,index)=>point.objectiveId===objectiveId?[index]:[]);if(exact.length)return exact;
 const objective=objectives.find(o=>o.id===objectiveId);
 const key=value=>String(value||'').normalize('NFKC').toLowerCase().replace(/ё/g,'е').replace(/[\s.,«»"“”]+/g,' ').trim();
 const label=key(objective?.description),labels=label?map.points.flatMap((point,index)=>key(point.name)===label?[index]:[]):[];
 return labels.length?labels:map.points.length===1?[0]:[];
}
