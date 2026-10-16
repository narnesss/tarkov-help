import {el} from './guide-common.mjs?v=quest-guides-20261016';
import {questMapPlan,questMapURL,questMapFocusIndexes,questMapKey,questMapForObjective} from './quest-map-model.mjs?v=quest-guides-20261016';
import {QUEST_MAP_READY,QUEST_MAP_FOCUS,questMapContextMatches} from './quest-map-messages.mjs';
let viewSequence=0;
export class QuestMapView {
 constructor(task,{mode,index,objectives}) {
  Object.assign(this,{task,mode,index});this.maps=questMapPlan(task,mode,index,objectives);this.root=el('section','','guide-section quest-map-section');this.root.id='quest-map-section';this.root.hidden=!this.maps.length;
  if(!this.maps.length)return;
  this.root.append(el('h3','Где выполнять задание'),el('p','Точки выполнения на карте. Выберите локацию; на карте доступны перемещение и масштабирование.','quest-map-intro'));
  const tools=el('div','','quest-map-tools'),label=el('label','Локация'),select=el('select');select.id='quest-inline-map';label.htmlFor=select.id;this.select=select;
  for(const map of this.maps){const option=el('option',map.name+' · '+map.count+' точек');option.value=questMapKey(map);select.append(option);}select.onchange=()=>this.show(select.value);
  this.full=el('a','Открыть карту отдельно ↗','button quiet');this.full.target='_blank';this.full.rel='noopener noreferrer';tools.append(label,select,this.full);this.root.append(tools);
  this.frame=el('iframe','','quest-map-frame');this.frame.loading='lazy';this.frame.setAttribute('allow','fullscreen');this.frame.setAttribute('allowfullscreen','');this.frame.referrerPolicy='same-origin';this.root.append(this.frame);
  this.listener=event=>{if(event.origin!==location.origin||event.source!==this.frame.contentWindow||event.data?.type!==QUEST_MAP_READY||!questMapContextMatches(event.data,this.context()))return;this.ready=true;this.deliver();};
  window.addEventListener('message',this.listener);
  this.caption=el('p','','quest-map-caption');this.root.append(this.caption);this.show(questMapKey(this.maps[0]));
 }
 context(){return this.current?{questId:this.task.id,mode:this.mode,mapId:this.current.renderer==='source'?this.current.sourceKey:this.current.id,viewId:this.viewId}:null;}
 show(id){
  const map=this.maps.find(m=>questMapKey(m)===id);if(!map)return;this.select.value=id;if(this.current&&questMapKey(this.current)===id)return;
  this.current=map;this.ready=false;this.pending=null;this.viewId=String(++viewSequence);
  this.frame.title='Места выполнения задания «'+this.task.name+'»: '+map.name;this.frame.setAttribute('src',questMapURL(this.task.id,this.mode,map,'',true)+'&view='+this.viewId);this.full.href=questMapURL(this.task.id,this.mode,map,'',false);
  this.caption.textContent=map.renderer==='source'?'Места выполнения на карте.':'Квестовые точки на нашей интерактивной карте. Этаж можно выбрать в блоке карты.';
 }
 deliver(){if(!this.ready||!this.pending||!this.frame.contentWindow)return;this.frame.contentWindow.postMessage(this.pending,location.origin);this.pending=null;}
 focus(mapId,objective='') {
  const selected=questMapForObjective(this.maps,mapId,objective);if(!selected)return false;this.show(questMapKey(selected));
  this.pending={type:QUEST_MAP_FOCUS,...this.context(),objectiveId:objective,pointIndexes:questMapFocusIndexes(this.current,objective,this.task.objectives)};this.deliver();
  this.full.href=questMapURL(this.task.id,this.mode,this.current,objective,false);this.frame.loading='eager';
  this.root.scrollIntoView?.({block:'start',behavior:globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});return true;
 }
 destroy(){if(this.listener)window.removeEventListener('message',this.listener);this.ready=false;this.pending=null;}
}
