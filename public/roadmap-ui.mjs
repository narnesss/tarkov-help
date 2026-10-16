import {action,el,external} from './guide-common.mjs?v=release-20261016';
import {readPersonal,writePersonal} from './profile-store.mjs';
import {TICKET_ROUTES,clampView,edgeRoute,findNodes,layoutGraph,zoomAt} from './roadmap-model.mjs';

let sequence=0;
const svgNode=(name,attributes={})=>{const n=document.createElementNS('http://www.w3.org/2000/svg',name);for(const [k,v] of Object.entries(attributes))n.setAttribute(k,String(v));return n;};

/** One instance owns its events, viewport, focus and private persistence. */
export class RoadmapView{
 constructor(graph,{mode='regular',stateId=graph.title,onQuest=()=>{},onObjective=()=>{},variant='default'}={}){
  this.variant=variant;this.graph=graph;this.mode=mode;this.stateId=stateId;this.onQuest=onQuest;this.onObjective=onObjective;
  this.key=`kord-atlas-quest-view-${mode}-v1`;this.uid='roadmap-'+(++sequence);this.listeners=[];this.pointers=new Map();this.dragged=false;
  const saved=readPersonal(this.key)?.roadmaps?.[this.stateId];this.orientation=variant==='ticket'?'vertical':['compact','horizontal','vertical'].includes(saved?.layout)?saved.layout:'compact';this.layoutOptions=variant==='ticket'?{nodeWidth:184,gapX:30,gapY:34,density:'ticket'}:{};this.layout=layoutGraph(graph,{...this.layoutOptions,layout:this.orientation});
  this.root=el('section','','roadmap'+(variant==='ticket'?' ticket-roadmap':''));this.root.setAttribute('aria-label','Интерактивная схема: '+graph.title);
  const toolbar=el('div','','roadmap-toolbar');
  this.search=el('input');this.search.type='search';this.search.placeholder='Найти шаг на схеме…';this.search.setAttribute('aria-label','Найти шаг на схеме');
  this.results=el('select');this.results.hidden=true;this.results.setAttribute('aria-label','Результаты поиска по схеме');
  const searchWrap=el('div','','roadmap-search');searchWrap.append(this.search,this.results);
  this.zoomLabel=el('output','','roadmap-zoom');this.zoomLabel.setAttribute('aria-label','Масштаб схемы');
  this.minus=action('−',()=>this.zoom(.8),'roadmap-control');this.minus.setAttribute('aria-label','Отдалить схему');
  this.plus=action('+',()=>this.zoom(1.25),'roadmap-control');this.plus.setAttribute('aria-label','Приблизить схему');
  this.fitButton=action('Вся схема',()=>this.fit(),'roadmap-control');
  this.resetButton=action('100%',()=>this.focusNode(this.graph.nodes[0]?.id,1),'roadmap-control');
  this.fullscreen=action('На весь экран',()=>this.openFull(),'roadmap-control');
  this.layoutControl=el('select','','roadmap-layout');this.layoutControl.setAttribute('aria-label','Расположение блоков');for(const [value,label] of [['compact','Компактная схема'],['horizontal','Слева направо'],['vertical','Сверху вниз']]){const option=el('option',label);option.value=value;this.layoutControl.append(option);}this.layoutControl.value=this.orientation;this.layoutControl.onchange=()=>this.setLayout(this.layoutControl.value);
  toolbar.append(searchWrap);if(variant!=='ticket')toolbar.append(this.layoutControl);toolbar.append(this.minus,this.zoomLabel,this.plus,this.fitButton,this.resetButton,this.fullscreen);this.root.append(toolbar);
  this.viewport=el('div','','roadmap-viewport');this.viewport.tabIndex=0;this.viewport.setAttribute('role','region');this.viewport.setAttribute('aria-label','Схема. Перетаскивание — перемещение, колесо — масштаб. Клавиши плюс, минус, стрелки и Home.');
  this.world=el('div','','roadmap-world');this.world.style.width=this.layout.width+'px';this.world.style.height=this.layout.height+'px';this.viewport.append(this.world);
  this.details=el('aside','','roadmap-node-details');this.details.hidden=true;this.details.setAttribute('aria-live','polite');this.viewport.append(this.details);
  this.root.append(this.viewport);
  const footer=el('div','','roadmap-footer');footer.append(el('span',`${graph.nodes.length} блоков · ${graph.edges.length} связей`),el('span','Мышь: тянуть / колесо. Телефон: тянуть / два пальца.'));this.root.append(footer);
  const legend=el('div','','roadmap-legend');if(variant==='ticket'){legend.append(el('span','Общий участок','roadmap-key common'));for(const [id,route] of Object.entries(TICKET_ROUTES)){const key=el('span',route.label,'roadmap-key route-'+id);key.style.setProperty('--route-color',route.color);legend.append(key);}legend.append(el('span','Выполнено','roadmap-key done'));}else for(const [type,label] of [['step','Шаг'],['choice','Решение'],['conditional','Условный шаг'],['optional','Дополнительно'],['ending','Концовка'],['done','Выполнено']])legend.append(el('span',label,'roadmap-key '+type));this.root.append(legend);
  this.draw();this.bind();this.view=clampView(saved);this.restore=!!saved?.layout;
 }
 listen(target,type,handler,options){target.addEventListener(type,handler,options);this.listeners.push(()=>target.removeEventListener(type,handler,options));}
 mount(parent){parent.append(this.root);if(this.restore)this.apply(false);else this.startView();return this;}
 startView(id){const node=this.graph.nodes.find(n=>n.id===id)||this.graph.nodes.find(n=>n.kind==='selected')||this.graph.nodes[0];this.focusNode(node?.id,this.variant==='ticket'?1:.8);const p=this.layout.positions.get(node?.id);if(p){this.view.y=35-p.y*this.view.scale;this.apply();}}
 setLayout(value){if(!['compact','horizontal','vertical'].includes(value)||value===this.orientation)return;this.orientation=value;this.layout=layoutGraph(this.graph,{...this.layoutOptions,layout:value});this.details.hidden=true;this.draw();this.startView(this.selectedNodeId);}
 size(){const r=this.viewport.getBoundingClientRect();return {width:this.viewport.clientWidth||r.width||700,height:this.viewport.clientHeight||r.height||560};}
 apply(save=true){this.world.style.transform=`translate(${this.view.x}px,${this.view.y}px) scale(${this.view.scale})`;this.zoomLabel.textContent=Math.round(this.view.scale*100)+'%';if(save)this.persist();}
 saveState(){const prefs=readPersonal(this.key)||{};try{writePersonal(this.key,{...prefs,roadmaps:{...(prefs.roadmaps||{}),[this.stateId]:{...clampView(this.view),layout:this.orientation}}});}catch{/* Profile storage handles offline fallback; the diagram remains interactive. */}}
 persist(){clearTimeout(this.saveTimer);this.saveTimer=setTimeout(()=>this.saveState(),120);}
 fit(save=true){const {width,height}=this.size(),scale=Math.max(.01,Math.min(.9,(width-44)/this.layout.width,(height-44)/this.layout.height));this.view={scale,x:(width-this.layout.width*scale)/2,y:(height-this.layout.height*scale)/2};this.apply(save);}
 zoom(factor,point){const s=this.size();this.view=zoomAt(this.view,factor,point||{x:s.width/2,y:s.height/2});this.apply();}
 focusNode(id,scale=Math.max(.75,this.view.scale)){const p=this.layout.positions.get(id);if(!p)return;const size=this.size();this.view=clampView({scale,x:size.width/2-(p.x+p.width/2)*scale,y:size.height/2-(p.y+p.height/2)*scale});this.apply();this.nodeElements.get(id)?.focus({preventScroll:true});}
 draw(){
  this.world.replaceChildren();this.world.style.width=this.layout.width+'px';this.world.style.height=this.layout.height+'px';
  const svg=svgNode('svg',{width:this.layout.width,height:this.layout.height,'aria-hidden':'true'});svg.classList.add('roadmap-edges');svg.style.width=this.layout.width+'px';svg.style.height=this.layout.height+'px';
  const defs=svgNode('defs'),marker=svgNode('marker',{id:this.uid+'-arrow',viewBox:'0 0 10 10',refX:9,refY:5,markerWidth:7,markerHeight:7,orient:'auto-start-reverse'});marker.append(svgNode('path',{d:'M 0 0 L 10 5 L 0 10 z',fill:'#9da89c'}));defs.append(marker);svg.append(defs);
  for(const edge of this.graph.edges){const a=this.layout.positions.get(edge.from),b=this.layout.positions.get(edge.to);if(!a||!b)continue;
   const route=edgeRoute(this.layout,a,b);
   const path=svgNode('path',{d:route.d,'marker-end':`url(#${this.uid}-arrow)`});if(edge.kind==='optional')path.setAttribute('stroke-dasharray','7 5');
   if(this.variant==='ticket'&&edge.routeColor)path.style.setProperty('--route-color',edge.routeColor);const title=svgNode('title');title.textContent=edge.label||(edge.paths||[]).join(' / ');path.append(title);svg.append(path);
   if(edge.label&&edge.label!=='Выполнить'){const text=svgNode('text',{x:route.label.x,y:route.label.y,'text-anchor':'middle'});text.textContent=edge.label;svg.append(text);}
  }
  this.world.append(svg);this.nodeElements=new Map();
  for(const node of this.graph.nodes){const p=this.layout.positions.get(node.id),button=action('',()=>{if(this.dragged)return;if(this.variant==='ticket'&&node.objectiveId){this.closeFull();this.onObjective(node.objectiveId);}else this.select(node);},'roadmap-node '+node.kind+(node.done?' is-done':''));button.dataset.node=node.id;
   Object.assign(button.style,{left:p.x+'px',top:p.y+'px',width:p.width+'px',minHeight:p.height+'px'});
   if(this.variant==='ticket'){button.style.setProperty('--route-color',node.routeColor||'#789a84');if(node.stepNumber)button.append(el('span',String(node.stepNumber).padStart(2,'0'),'ticket-node-number'));}button.append(el('strong',node.label),el('small',(node.done?'✓ ':'')+(node.subtitle||'')));button.setAttribute('aria-label',node.label+(node.done?' — выполнено':''));this.world.append(button);this.nodeElements.set(node.id,button);
  }
 }
 select(node){
  this.selectedNodeId=node.id;for(const [id,button] of this.nodeElements){button.classList.toggle('is-current',id===node.id);button.setAttribute('aria-pressed',String(id===node.id));}
  this.details.replaceChildren(action('×',()=>{this.details.hidden=true;},'roadmap-detail-close'),el('strong',node.label));
  for(const text of [...(node.guide||[]),...(node.conditions||[])])this.details.append(el('p',typeof text==='string'?text:text.description||''));
  if(node.questId)this.details.append(action('Открыть задание',()=>{const id=node.questId;this.closeFull();this.onQuest(id);},'button'));
  if(node.objectiveId)this.details.append(action('Открыть подробности цели',()=>{const id=node.objectiveId;this.closeFull();this.onObjective(id);},'button'));
  if(node.sourceUrl)this.details.append(external('Источник ↗',node.sourceUrl));this.details.hidden=false;
 }
 bind(){
  const interactiveDetails=event=>event.target.closest?.('.roadmap-node-details');
  this.listen(this.viewport,'selectstart',event=>{if(!interactiveDetails(event))event.preventDefault();});
  this.listen(this.viewport,'dragstart',event=>{if(!interactiveDetails(event))event.preventDefault();});
  this.listen(this.search,'input',()=>{const matches=findNodes(this.graph,this.search.value);this.results.replaceChildren();this.results.hidden=!this.search.value;
   const none=el('option',matches.length?'Найдено: '+matches.length:'Нет совпадений');none.value='';this.results.append(none);
   for(const n of matches.slice(0,150)){const option=el('option',n.label);option.value=n.id;this.results.append(option);}
   for(const [id,button] of this.nodeElements)button.classList.toggle('is-match',matches.some(n=>n.id===id));if(matches.length)this.focusNode(matches[0].id);
  });
  this.listen(this.results,'change',()=>this.focusNode(this.results.value));
  this.listen(this.viewport,'wheel',event=>{if(event.target.closest?.('.roadmap-node-details'))return;event.preventDefault();const rect=this.viewport.getBoundingClientRect();this.zoom(Math.exp(-event.deltaY*.0015),{x:event.clientX-rect.left,y:event.clientY-rect.top});},{passive:false});
  const distance=points=>Math.hypot(points[0].x-points[1].x,points[0].y-points[1].y);
  this.listen(this.viewport,'pointerdown',event=>{if(event.button>0||interactiveDetails(event))return;event.preventDefault();event.target.closest?.('.roadmap-node')?.focus({preventScroll:true});this.dragged=false;this.pointers.set(event.pointerId,{x:event.clientX,y:event.clientY});this.gesture={view:{...this.view},points:[...this.pointers.values()]};if(!event.target.closest?.('.roadmap-node'))this.viewport.setPointerCapture?.(event.pointerId);this.viewport.classList.add('is-dragging');});
  this.listen(this.viewport,'pointermove',event=>{if(!this.pointers.has(event.pointerId))return;this.pointers.set(event.pointerId,{x:event.clientX,y:event.clientY});const points=[...this.pointers.values()],start=this.gesture.points;
   if(points.length===2&&start.length===2){const rect=this.viewport.getBoundingClientRect(),center={x:(start[0].x+start[1].x)/2-rect.left,y:(start[0].y+start[1].y)/2-rect.top};this.view=zoomAt(this.gesture.view,distance(points)/Math.max(1,distance(start)),center);this.view.x+=(points[0].x+points[1].x-start[0].x-start[1].x)/2;this.view.y+=(points[0].y+points[1].y-start[0].y-start[1].y)/2;this.dragged=true;
   }else{const dx=points[0].x-start[0].x,dy=points[0].y-start[0].y;this.dragged ||= Math.hypot(dx,dy)>5;this.view={...this.gesture.view,x:this.gesture.view.x+dx,y:this.gesture.view.y+dy};}if(this.dragged)this.viewport.setPointerCapture?.(event.pointerId);this.apply();
  });
  const end=event=>{this.pointers.delete(event.pointerId);this.viewport.releasePointerCapture?.(event.pointerId);this.gesture={view:{...this.view},points:[...this.pointers.values()]};if(!this.pointers.size){this.viewport.classList.remove('is-dragging');setTimeout(()=>this.dragged=false,0);}};
  this.listen(this.viewport,'pointerup',end);this.listen(this.viewport,'pointercancel',end);
  this.listen(this.viewport,'keydown',event=>{if(event.target!==this.viewport)return;const moves={ArrowLeft:[70,0],ArrowRight:[-70,0],ArrowUp:[0,70],ArrowDown:[0,-70]};if(moves[event.key]){event.preventDefault();this.view.x+=moves[event.key][0];this.view.y+=moves[event.key][1];this.apply();}else if(['+','=','-','Home'].includes(event.key)){event.preventDefault();event.key==='Home'?this.fit():this.zoom(event.key==='-'?.8:1.25);}});
 }
 openFull(){
  if(this.dialog)return;this.placeholder=el('span');this.root.before(this.placeholder);this.beforeFull={...this.view};this.beforeFullLayout=this.orientation;
  const dialog=el('dialog','','roadmap-dialog'),heading=el('div','','roadmap-dialog-heading');heading.append(el('h2',this.graph.title),action('Закрыть',()=>this.closeFull(),'button'));dialog.append(heading,this.root);document.body.append(dialog);this.dialog=dialog;this.fullscreen.hidden=true;
  dialog.addEventListener('cancel',event=>{event.preventDefault();this.closeFull();});dialog.showModal();this.fit(false);this.viewport.focus();
 }
 closeFull(){if(!this.dialog)return;const dialog=this.dialog;this.dialog=null;dialog.close();this.placeholder.replaceWith(this.root);dialog.remove();this.fullscreen.hidden=false;if(this.beforeFullLayout===this.orientation){this.view=this.beforeFull;this.apply(false);}else this.startView(this.selectedNodeId);}
 destroy(){clearTimeout(this.saveTimer);this.closeFull();if(this.view)this.saveState();for(const remove of this.listeners)remove();this.listeners=[];this.root.remove();}
}
