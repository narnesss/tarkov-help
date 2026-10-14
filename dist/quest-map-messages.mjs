export const QUEST_MAP_READY='kord:quest-map-ready';
export const QUEST_MAP_FOCUS='kord:quest-map-focus';

export function questMapContextMatches(message,context){
 return !!message&&!!context&&message.questId===context.questId&&message.mode===context.mode&&message.mapId===context.mapId&&message.viewId===context.viewId;
}

/** Queue the last focus request until the map's layers have finished loading. */
export class QuestMapFocusReceiver {
 constructor(context,onFocus,{enabled=true}={}){
  Object.assign(this,{context,onFocus,enabled});this.loaded=false;this.pending=null;
  this.listener=event=>{
   if(!this.enabled||!window.parent||window.parent===window||event.source!==window.parent||event.origin!==location.origin)return;
   const message=event.data;
   if(message?.type!==QUEST_MAP_FOCUS||!questMapContextMatches(message,this.context())||typeof message.objectiveId!=='string')return;
   if(message.pointIndexes!==undefined&&(!Array.isArray(message.pointIndexes)||!message.pointIndexes.every(i=>Number.isInteger(i)&&i>=0)))return;
   if(this.loaded)this.onFocus(message);else this.pending=message;
  };
  window.addEventListener('message',this.listener);
 }
 wait(){this.loaded=false;}
 ready(){
  this.loaded=true;
  if(this.pending){const message=this.pending;this.pending=null;if(questMapContextMatches(message,this.context()))this.onFocus(message);}
  if(this.enabled&&window.parent&&window.parent!==window)window.parent.postMessage({type:QUEST_MAP_READY,...this.context()},location.origin);
 }
 destroy(){window.removeEventListener('message',this.listener);this.pending=null;}
}
