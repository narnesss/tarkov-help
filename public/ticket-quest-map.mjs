import {el,action} from './guide-common.mjs?v=lazy-20261016';
import {readPersonal,writePersonal} from './profile-store.mjs';

const endings={savior:'Спаситель',debtor:'Должник',survivor:'Выживший',fallen:'Падший'};
const visible=(nodes,mode)=>(nodes||[]).filter(node=>!node.modes||node.modes.includes(mode));

/** A compact nested decision tree with its own native scrolling viewport. */
export class TicketQuestMap {
 constructor(model,{mode='pve',stateId='the-ticket'}={}){
  this.model=model||{groups:[],colors:{}};this.mode=mode;this.key='kord-atlas-quest-view-'+mode+'-v1';this.stateId=stateId;this.listeners=[];
  const saved=readPersonal(this.key)?.ticketMaps?.[stateId];this.scale=Math.max(.4,Math.min(1.4,saved?.scale||(globalThis.innerWidth<=700?.55:1)));this.saved=saved;
  this.root=el('div','','ticket-quest-map');const controls=el('div','','ticket-map-controls');
  this.output=el('output');this.output.setAttribute('aria-label','Масштаб карты квеста');
  controls.append(action('−',()=>this.zoom(-.1),'ticket-map-control'),this.output,action('+',()=>this.zoom(.1),'ticket-map-control'),action('Сброс',()=>this.reset(),'ticket-map-control'));
  this.viewport=el('div','','ticket-map-viewport');this.viewport.tabIndex=0;this.viewport.setAttribute('role','region');this.viewport.setAttribute('aria-label','Карта квеста Билет. Колесо — масштаб, перетаскивание — перемещение.');
  this.stage=el('div','','ticket-map-stage');this.tree=el('div','','ticket-map-tree');this.stage.append(this.tree);this.viewport.append(this.stage);
  this.tree.append(this.card({label:'Билет',subtitle:'НАЧАЛО ГЛАВЫ',route:'common'},'start'));
  const branches=el('div','','ticket-map-branches ticket-map-main-branches');for(const group of this.model.groups||[])branches.append(this.group(group));this.tree.append(branches);this.root.append(controls,this.viewport);this.bind();
 }
 card(node,kind='decision'){
  const card=el('div','','ticket-map-card '+kind);card.style.setProperty('--ticket-route',this.model.colors?.[node.route||'common']||'#36ff99');
  if(node.number)card.append(el('span',node.number,'ticket-map-number'));if(node.subtitle)card.append(el('span',node.subtitle,'ticket-map-subtitle'));
  card.append(el('span',node.label,'ticket-map-label'));if(node.objectiveId)card.dataset.objective=node.objectiveId;if(node.objectiveIds?.length)card.dataset.objectives=node.objectiveIds.join(' ');return card;
 }
 stack(nodes){const stack=el('div','','ticket-map-stack');for(const node of visible(nodes,this.mode)){if(stack.children.length)stack.append(el('span','','ticket-map-step-arrow'));stack.append(this.card(node,'task'));}return stack;}
 group(node){
  const branch=el('div','','ticket-map-branch');branch.dataset.branch=node.id;
  if(node.commonTasks?.length)branch.append(this.stack(node.commonTasks));
  if(node.label)branch.append(this.card(node));
  if(node.tasks?.length)branch.append(this.stack(node.tasks));
  if(node.children?.length){const children=el('div','','ticket-map-branches');for(const child of node.children)children.append(this.group(child));branch.append(children);}
  if(node.ending)branch.append(el('span','','ticket-map-step-arrow'),this.card({label:endings[node.ending],subtitle:'КОНЦОВКА',route:node.ending},'ending'));
  return branch;
 }
 mount(parent){parent.append(this.root);this.measure();if(this.saved){this.viewport.scrollLeft=this.saved.left||0;this.viewport.scrollTop=this.saved.top||0;}else this.center();if(globalThis.ResizeObserver){this.observer=new ResizeObserver(()=>this.measure());this.observer.observe(this.tree);}return this;}
 size(){return {width:this.tree.scrollWidth||Math.max(168,(this.model.groups?.length||1)*360),height:this.tree.scrollHeight||2400};}
 measure(){const size=this.size();this.tree.style.transform=`scale(${this.scale})`;this.stage.style.width=size.width*this.scale+'px';this.stage.style.height=size.height*this.scale+'px';this.output.textContent=Math.round(this.scale*100)+'%';}
 center(){this.viewport.scrollLeft=Math.max(0,(this.size().width*this.scale-(this.viewport.clientWidth||700))/2);this.viewport.scrollTop=0;}
 zoom(delta,point){const rect=this.viewport.getBoundingClientRect(),x=point?.x??rect.width/2,y=point?.y??rect.height/2,old=this.scale,next=Math.max(.4,Math.min(1.4,Math.round((old+delta)*100)/100));if(next===old)return;const anchor={x:(this.viewport.scrollLeft+x)/old,y:(this.viewport.scrollTop+y)/old};this.scale=next;this.measure();this.viewport.scrollLeft=anchor.x*next-x;this.viewport.scrollTop=anchor.y*next-y;this.persist();}
 reset(){this.scale=globalThis.innerWidth<=700?.55:1;this.measure();this.center();this.persist();}
 persist(){clearTimeout(this.timer);this.timer=setTimeout(()=>{const old=readPersonal(this.key)||{};writePersonal(this.key,{...old,ticketMaps:{...old.ticketMaps,[this.stateId]:{scale:this.scale,left:this.viewport.scrollLeft,top:this.viewport.scrollTop}}});},120);}
 listen(target,type,callback,options){target.addEventListener(type,callback,options);this.listeners.push(()=>target.removeEventListener(type,callback,options));}
 bind(){
  this.listen(this.viewport,'wheel',event=>{event.preventDefault();const rect=this.viewport.getBoundingClientRect();this.zoom(event.deltaY<0?.1:-.1,{x:event.clientX-rect.left,y:event.clientY-rect.top});},{passive:false});
  this.listen(this.viewport,'pointerdown',event=>{if(event.button>0)return;this.drag={id:event.pointerId,x:event.clientX,y:event.clientY,left:this.viewport.scrollLeft,top:this.viewport.scrollTop};this.viewport.setPointerCapture?.(event.pointerId);this.viewport.classList.add('is-dragging');event.preventDefault();});
  this.listen(this.viewport,'pointermove',event=>{if(this.drag?.id!==event.pointerId)return;this.viewport.scrollLeft=this.drag.left+this.drag.x-event.clientX;this.viewport.scrollTop=this.drag.top+this.drag.y-event.clientY;});
  const end=event=>{if(this.drag?.id!==event.pointerId)return;this.viewport.releasePointerCapture?.(event.pointerId);this.drag=null;this.viewport.classList.remove('is-dragging');this.persist();};this.listen(this.viewport,'pointerup',end);this.listen(this.viewport,'pointercancel',end);
  this.listen(this.viewport,'scroll',()=>this.persist(),{passive:true});this.listen(this.viewport,'keydown',event=>{if(event.key==='Home'){event.preventDefault();this.reset();}else if(['+','=','-'].includes(event.key)){event.preventDefault();this.zoom(event.key==='-'?-.1:.1);}});
 }
 destroy(){clearTimeout(this.timer);this.observer?.disconnect();for(const off of this.listeners)off();this.root.remove();}
}
