import {normalize} from './model.mjs?v=page-content-20261017';
import {idOf,questState} from './quest-model.mjs?v=page-content-20261017';

export function chapterGraph(task,{mode='regular',branch='',done=new Set()}={}){
 const visible=(task.objectives||[]).filter(o=>!o.modes||o.modes.includes(mode)),byId=new Map(visible.map(o=>[o.id,o]));
 const paths=task.storyBranches?.length?task.storyBranches.filter(b=>!branch||b.id===branch):[{id:'main',name:'Основной путь',objectiveIds:visible.map(o=>o.id)}];
 const nodes=new Map(),edges=new Map();
 const connect=(a,b,path,kind='step')=>{if(!a||!b||a===b)return;const key=a+'>'+b;const edge=edges.get(key)||{id:key,from:a,to:b,paths:[],kind};if(kind==='optional')edge.label='Дополнительно';if(!edge.paths.includes(path.name))edge.paths.push(path.name);edges.set(key,edge);};
 for(const path of paths){let previous;
  for(const id of path.objectiveIds||[]){const o=byId.get(id);if(!o)continue;
   nodes.set(id,{id,label:o.description,subtitle:o.optional?'Необязательный шаг':o.conditions?.length?'Условный шаг (см. подробности)':o.phase==='terminal'?'Финальный рейд':'Сюжетная цель',kind:o.roadmapKind||(o.optional?'optional':'step'),objectiveId:id,done:done.has(id),guide:o.guide||[],conditions:o.conditions||[],sourceUrl:o.sourceUrl});connect(previous,id,path,o.optional?'optional':'step');if(!o.optional)previous=id;
  }
  if(path.ending){const final=nodes.get(previous);if(final?.kind==='ending'){final.ending=path.ending;}else{const id='ending-'+path.ending;nodes.set(id,{id,label:'Концовка «'+path.endingName+'»',subtitle:'Побег из Таркова',kind:'ending',ending:path.ending,guide:path.endingDescription?[path.endingDescription]:[]});connect(previous,id,path);}}
 }
 if(!paths.length)return {nodes:[],edges:[],title:task.name};
 return {nodes:[...nodes.values()],edges:[...edges.values()],title:task.name};
}

export const TICKET_ROUTES={survivor:{label:'Выживший',color:'#85c9ff'},debtor:{label:'Должник',color:'#ffbf70'},savior:{label:'Спаситель',color:'#9affbc'},fallen:{label:'Падший',color:'#f69b9b'}};
/** Preserve the source graph, annotate the real endings and add a visual start. */
export function ticketGraph(task,options={}){
 const graph=chapterGraph(task,options);
 const routes=new Map(graph.nodes.map(n=>[n.id,new Set(n.ending?[n.ending]:[])]));
 let changed=true;
 while(changed){changed=false;for(const edge of graph.edges)for(const route of routes.get(edge.to)||[]){const set=routes.get(edge.from);if(set&&!set.has(route)){set.add(route);changed=true;}}}
 const color=values=>values.size===1?TICKET_ROUTES[[...values][0]]?.color||'#a0c4ad':'#789a84';
 const numbers=new Map(orderedObjectives(task,options.branch||'',options.mode||'regular').map((o,i)=>[o.id,i+1]));
 for(const node of graph.nodes){node.routes=[...routes.get(node.id)];node.routeColor=color(routes.get(node.id));node.stepNumber=numbers.get(node.objectiveId);}
 for(const edge of graph.edges)edge.routeColor=color(routes.get(edge.to)||new Set());
 if(graph.nodes.length){
  const children=new Set(graph.edges.map(e=>e.to)),roots=graph.nodes.filter(n=>!children.has(n.id));
  graph.nodes.unshift({id:'ticket-start',label:'Начало главы «Билет»',subtitle:'Путь из Таркова',kind:'start',routeColor:'#9affbc'});
  for(const node of roots)graph.edges.unshift({id:'ticket-start>'+node.id,from:'ticket-start',to:node.id,routeColor:'#9affbc'});
 }
 return graph;
}

export function questGraph(selected,tasks,{scope='chain',progress={quests:{}},traders=new Map()}={}){
 const byId=new Map(tasks.filter(t=>!t.story).map(t=>[t.id,t]));
 const included=new Set();
 if(scope==='all')for(const id of byId.keys())included.add(id);
 else if(scope==='trader')for(const t of byId.values())if(idOf(t.trader)===idOf(selected.trader))included.add(t.id);
 else{
  const queue=[selected.id],followers=new Map();
  for(const task of byId.values())for(const r of task.taskRequirements||[]){const id=idOf(r.task);if(!followers.has(id))followers.set(id,[]);followers.get(id).push(task.id);}
  while(queue.length){const id=queue.pop();if(included.has(id)||!byId.has(id))continue;included.add(id);queue.push(...(byId.get(id).taskRequirements||[]).map(r=>idOf(r.task)),...(followers.get(id)||[]));}
 }
 // Show external prerequisites of the selected trader as actual linked nodes.
 if(scope==='trader'){const seeds=[...included];for(const id of seeds)for(const r of byId.get(id).taskRequirements||[])if(byId.has(idOf(r.task)))included.add(idOf(r.task));}
 const edges=[];
 const nodes=[...included].map(id=>{const t=byId.get(id),state=questState(t,progress);return {id,label:t.name,subtitle:(traders.get(idOf(t.trader))?.name||'Торговец')+' · '+(t.minPlayerLevel||1)+' ур.',questId:id,kind:id===selected.id?'selected':'quest',done:state==='complete',state};});
 for(const id of included){const task=byId.get(id);for(const [i,r] of (task.taskRequirements||[]).entries()){const from=idOf(r.task);if(!included.has(from))continue;const states=r.status?.length?r.status:['complete'];const labels={complete:'Выполнить',active:'Начать',failed:'Провалить',started:'Начать',availableForFinish:'Готово к сдаче'};edges.push({id:from+'>'+id+':'+i,from,to:id,label:states.map(s=>labels[s]||'Особое условие').join(' / '),statuses:states});}}
 return {nodes,edges,title:scope==='all'?'Все задания':scope==='trader'?'Задания торговца':selected.name};
}

// Strongly connected components keep real alternate/failure relations intact.
// We do not silently delete a relation to force a tree.
export function layoutGraph(graph,{nodeWidth=284,gapX=68,gapY=72,layout='vertical',columns=4,density='default'}={}){
 const byId=new Map(graph.nodes.map(n=>[n.id,n])),links=new Map(graph.nodes.map(n=>[n.id,[]]));
 for(const edge of graph.edges)if(byId.has(edge.from)&&byId.has(edge.to))links.get(edge.from).push(edge.to);
 let nextIndex=0;const stack=[],onStack=new Set(),indices=new Map(),low=new Map(),components=[];
 const visit=id=>{indices.set(id,nextIndex);low.set(id,nextIndex++);stack.push(id);onStack.add(id);for(const target of links.get(id)){if(!indices.has(target)){visit(target);low.set(id,Math.min(low.get(id),low.get(target)));}else if(onStack.has(target))low.set(id,Math.min(low.get(id),indices.get(target)));}if(low.get(id)===indices.get(id)){const group=[];let top;do{top=stack.pop();onStack.delete(top);group.push(top);}while(top!==id);components.push(group);}};
 for(const id of byId.keys())if(!indices.has(id))visit(id);
 const componentOf=new Map();components.forEach((ids,i)=>ids.forEach(id=>componentOf.set(id,i)));
 const outgoing=new Map(components.map((_,i)=>[i,new Set()])),degree=components.map(()=>0),rank=components.map(()=>0);
 for(const e of graph.edges){const a=componentOf.get(e.from),b=componentOf.get(e.to);if(a===undefined||b===undefined||a===b||outgoing.get(a).has(b))continue;outgoing.get(a).add(b);degree[b]++;}
 const queue=degree.flatMap((n,i)=>n===0?[i]:[]);
 for(let i=0;i<queue.length;i++){const a=queue[i];for(const b of outgoing.get(a)){rank[b]=Math.max(rank[b],rank[a]+1);if(--degree[b]===0)queue.push(b);}}
 const rows=new Map();for(const node of graph.nodes){const r=rank[componentOf.get(node.id)];if(!rows.has(r))rows.set(r,[]);rows.get(r).push(node);}
 const stages=[...rows].sort(([a],[b])=>a-b),positions=new Map(),parents=new Map(graph.nodes.map(n=>[n.id,[]])),order=new Map(graph.nodes.map((n,i)=>[n.id,i]));
 for(const e of graph.edges)parents.get(e.to)?.push(e.from);
 const minHeight=density==='ticket'?88:132;
 const nodeHeight=n=>density==='ticket'?Math.max(minHeight,46+Math.ceil(n.label.length/24)*16+Math.ceil((n.subtitle||'').length/32)*12):Math.max(minHeight,84+Math.ceil(n.label.length/25)*24+Math.ceil((n.subtitle||'').length/36)*17);
 const sort=(nodes,axis)=>nodes.sort((a,b)=>{
  const center=n=>{const ps=parents.get(n.id).map(id=>positions.get(id)).filter(Boolean);return ps.length?ps.reduce((sum,p)=>sum+p[axis],0)/ps.length:Infinity;};
  return center(a)-center(b)||order.get(a.id)-order.get(b.id);
 });
 let width=80,height=80;const bands=[];
 if(layout==='horizontal'){
  height=Math.max(80,...stages.map(([,nodes])=>nodes.reduce((h,n)=>h+nodeHeight(n)+gapY,80)-gapY));
  stages.forEach(([r,nodes],index)=>{sort(nodes,'y');let y=(height-nodes.reduce((h,n)=>h+nodeHeight(n)+gapY,0)+gapY)/2;
   for(const n of nodes){const h=nodeHeight(n);positions.set(n.id,{x:40+index*(nodeWidth+gapX),y,width:nodeWidth,height:h,rank:r,flow:1});y+=h+gapY;}
  });width=80+stages.length*(nodeWidth+gapX)-gapX;
 }else if(layout==='compact'){
  const count=Math.min(Math.max(1,Math.floor(columns)),Math.max(1,stages.length));width=80+count*nodeWidth+(count-1)*gapX;let y=40;
  for(let offset=0;offset<stages.length;offset+=count){const band=stages.slice(offset,offset+count),index=bands.length,flow=index%2?-1:1;
   const h=Math.max(minHeight,...band.map(([,nodes])=>nodes.reduce((sum,n)=>sum+nodeHeight(n)+42,0)-42));
   bands.push({top:y,bottom:y+h});
   band.forEach(([r,nodes],column)=>{sort(nodes,'y');let top=y;
    for(const n of nodes){const nh=nodeHeight(n);positions.set(n.id,{x:40+(flow===1?column:count-1-column)*(nodeWidth+gapX),y:top,width:nodeWidth,height:nh,rank:r,band:index,flow});top+=nh+42;}
   });y+=h+gapY+28;
  }height=y+12;
 }else{
  layout='vertical';const maxRow=Math.max(1,...stages.map(([,nodes])=>nodes.length));width=80+maxRow*nodeWidth+(maxRow-1)*gapX;let y=40;
  for(const [r,nodes] of stages){sort(nodes,'x');const start=(width-nodes.length*nodeWidth-(nodes.length-1)*gapX)/2;
   nodes.forEach((n,i)=>positions.set(n.id,{x:start+i*(nodeWidth+gapX),y,width:nodeWidth,height:nodeHeight(n),rank:r}));y+=Math.max(minHeight,...nodes.map(nodeHeight))+gapY;
  }height=y+12;
 }
 return {width:Math.max(80,width),height:Math.max(80,height),positions,bands,layout,cycles:components.filter(c=>c.length>1)};
}

/** Route links through the free gutters, including wrapped rows in compact view. */
export function edgeRoute(layout,a,b){
 if(layout.layout==='vertical'||a.rank===b.rank){const x1=a.x+a.width/2,y1=a.y+a.height,x2=b.x+b.width/2,y2=b.y,mid=(y1+y2)/2;return {d:y2>y1?`M${x1},${y1} V${mid} H${x2} V${y2}`:`M${x1},${y1} C${x1+100},${y1+65} ${x2+100},${y2-65} ${x2},${y2}`,label:{x:(x1+x2)/2,y:mid-9}};}
 const flow=a.flow||1,x1=a.x+(flow===1?a.width:0),y1=a.y+a.height/2,targetFlow=b.flow||1,x2=b.x+(targetFlow===1?0:b.width),y2=b.y+b.height/2;
 if(layout.layout==='compact'&&a.band!==b.band){const sx=x1+flow*23,tx=x2-targetFlow*23,y=layout.bands[a.band].bottom+34;return {d:`M${x1},${y1} H${sx} V${y} H${tx} V${y2} H${x2}`,label:{x:(sx+tx)/2,y:y-9}};}
 const mid=(x1+x2)/2;return {d:`M${x1},${y1} H${mid} V${y2} H${x2}`,label:{x:mid,y:(y1+y2)/2-9}};
}

export function orderedObjectives(task,branch='',mode='regular'){
 const selected=task.storyBranches?.find(b=>b.id===branch),byId=new Map((task.objectives||[]).map(o=>[o.id,o]));
 const list=selected?selected.objectiveIds.map(id=>byId.get(id)).filter(Boolean):task.objectives||[];
 return list.filter(o=>!o.modes||o.modes.includes(mode));
}
export function findNodes(graph,query){const words=normalize(query).split(/\s+/).filter(Boolean);return words.length?graph.nodes.filter(n=>words.every(w=>normalize(n.label+' '+(n.subtitle||'')).includes(w))):[];}
export function clampView(value){return {x:Number.isFinite(value?.x)?value.x:0,y:Number.isFinite(value?.y)?value.y:0,scale:Math.max(.01,Math.min(2.5,Number(value?.scale)||1))};}
export function zoomAt(view,factor,point){const scale=Math.max(.01,Math.min(2.5,view.scale*factor));return {scale,x:point.x-(point.x-view.x)*scale/view.scale,y:point.y-(point.y-view.y)*scale/view.scale};}
