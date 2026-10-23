import {discardPointDraft} from './admin-map-drafts.mjs';

export function documentPointKey(point) { return point.id ?? `${point.kind}:${point.pointId??'new'}:${point.positionId??0}`; }
export function documentPoints(snapshot) { return snapshot.points.map(point=>({...point,id:documentPointKey(point),description:point.description||'',archived:!!point.archived})); }
export function documentChange(point) {
  return {clientId:point.id,kind:point.kind,pointId:point.pointId??null,positionId:point.positionId??null,
    title:point.name,description:point.description||'',floor:point.floor,x:point.x,y:point.y,archived:!!point.archived,
    ...(!point.pointId&&point.kind==='document'?{documentId:point.documentId??null}:{})};
}
export function updateDocumentDraft(points,pending,point,patch) {
  const shared=Object.fromEntries(Object.entries(patch).filter(([key])=>['name','description','floor','archived'].includes(key)));
  const affected=points.filter(row=>row===point||(point.pointId&&row.pointId===point.pointId&&row.kind===point.kind&&Object.keys(shared).length));
  if(new Set([...pending.keys(),...affected.map(row=>row.id)]).size>100)throw Error('Сохраните текущие правки перед следующими: не более 100 позиций за запрос.');
  Object.assign(point,patch);
  for(const row of affected){
    if(row!==point)Object.assign(row,shared);
    pending.set(row.id,documentChange(row));
  }
}
export function discardDocumentDraft(points,saved,pending,point) {
  const ids=points.filter(row=>row.id===point.id||(point.pointId&&row.pointId===point.pointId&&row.kind===point.kind)).map(row=>row.id);
  let selected=null;
  for(const id of ids){const restored=discardPointDraft(points,saved,pending,id);if(id===point.id)selected=restored;}
  return selected;
}
export function rebaseDocumentDrafts(snapshot,points,pending) {
  const server=documentPoints(snapshot),nextPending=new Map(),ids=new Map();
  for(const [id,change] of pending){
    const local=points.find(point=>point.id===id),remote=server.find(point=>point.id===id||(!change.pointId&&point.clientId===id));
    if(!local||(!remote&&change.pointId))throw Error('Одна из точек удалена на сервере. Скопируйте её правки из сравнения перед загрузкой серверной версии.');
    if(!snapshot.floors.includes(change.floor)&&!change.archived)throw Error('Изменилась схема этажей. Скопируйте координаты из сравнения перед загрузкой серверной версии.');
    const merged={...(remote||local),name:change.title,description:change.description,floor:change.floor,x:change.x,y:change.y,archived:change.archived};
    if(remote)server[server.indexOf(remote)]=merged;else server.push(merged);
    ids.set(id,merged.id);nextPending.set(merged.id,documentChange(merged));
  }
  return {points:server,pending:nextPending,ids};
}
