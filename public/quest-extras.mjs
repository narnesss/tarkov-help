import {applyCatalogUpdate} from './quest-catalog-update.mjs?v=page-content-20261017';
import {applyQuestPatch} from './quest-patch-update.mjs?v=page-content-20261017';
import {loadContentBundle} from './content-bundles.mjs?v=loading-20261018';
import {localizeDisplay} from './ru-data.mjs?v=page-content-20261017';
import {assemblyFrames} from './gunsmith-model.mjs?v=page-content-20261017';
import {applyQuestContent} from './quest-content-model.mjs?v=page-content-20261017';
import {localizeQuestMedia,localizeQuestMediaTask,mergeQuestMediaLocalization} from './quest-media-model.mjs?v=page-content-20261017';
import {json} from './guide-common.mjs?v=previews-20261019';
export {localizeQuestMedia,localizeQuestMediaTask,mergeQuestMediaLocalization};
let loaded;
let uiLoaded;
let mediaLoaded;
export function questMediaLocalization(){return mediaLoaded||=json('data/guide/media-localization.json').catch(error=>{mediaLoaded=null;throw error;});}
export function questUiExtras(){return uiLoaded||=loadContentBundle('quest-ui').then(([choices,questMaps,rewardSupport,ticketMap,conditions])=>({choices,questMaps,rewardSupport,ticketMap,conditions,uiReady:true})).catch(error=>{uiLoaded=null;throw error;});}
export function storyGallery(task){const byUrl=new Map();for(const o of task.objectives||[])for(const frame of o.media||[]){if(!frame.url)continue;const old=byUrl.get(frame.url);if(old){if(!old.objectiveIds.includes(o.id))old.objectiveIds.push(o.id);}else byUrl.set(frame.url,{...frame,id:frame.id||frame.originalUrl||frame.url,url:frame.url,fullUrl:frame.fullUrl||frame.url,sourceUrl:frame.sourceUrl||frame.source,objectiveIds:[o.id],association:'objective'});}return [...byUrl.values()];}
export function questExtras(){return loaded||=loadContentBundle('quest-extras').then(([media,story,conditions,patches,localization,wiki,gunsmith,catalog,questMaps,choices,rewardSupport,questPatch,ticketMap,content])=>{for(const task of Object.values(media.quests||{}))if(task.gallery)task.gallery=task.gallery.map(frame=>localizeQuestMedia(frame,localization));for(const task of story.tasks||[]){for(const objective of task.objectives||[])if(objective.media)objective.media=objective.media.map(frame=>localizeQuestMedia(frame,localization));task.gallery=storyGallery(task);}return {media,story,conditions,patches,wiki,gunsmith,catalog,questMaps,choices,rewardSupport,questPatch,ticketMap,content};}).catch(error=>{loaded=null;throw error;});}
export function enrichQuests(bundle,extras){
 const stories=extras?.story?.tasks||[],storyTrader=extras?.story?.trader||{id:'story',name:'Основной сюжет'},tasks=[...bundle.tasks.filter(t=>!t.story),...stories];
 const traders=(Array.isArray(bundle.traders)?bundle.traders:Object.values(bundle.traders||{})).filter(t=>t.id!=='story');if(stories.length)traders.unshift(storyTrader);
 const questItems=[...new Map([...(Array.isArray(bundle.questItems)?bundle.questItems:Object.values(bundle.questItems||{})),...(extras?.story?.questItems||[])].map(i=>[i.id,i])).values()];
 const updated=applyQuestPatch(applyCatalogUpdate({...bundle,tasks},extras?.catalog),extras?.questPatch);
 return localizeDisplay({...updated,questItems,traders,tasks:updated.tasks.map(task=>{
  const gunsmith=extras?.gunsmith?.quests?.[task.id],frames=assemblyFrames(gunsmith),gallery=task.gallery||(task.story?storyGallery(task):[]),media=extras?.media?.quests?.[task.id]||task.media;
  return applyQuestContent({...task,gunsmith,wikiFacts:extras?.wiki?.quests?.[task.id],wikiLink:extras?.wiki?.quests?.[task.id]?.source?.url||task.wikiLink,objectives:(task.objectives||[]).map(o=>({...o,description:/[А-ЯЁа-яё]/.test(o.description)?o.description:extras?.patches?.objectives?.[o.id]||o.description})),gallery:[...gallery,...frames],media:gunsmith?{...media,gallery:[...(media?.gallery||gallery),...frames]}:media,additionalConditions:extras?.conditions?.quests?.[task.id]||task.additionalConditions},extras?.content,bundle.meta?.mode||bundle.mode);
 }),storyCount:stories.length,wikiCoverage:extras?.wiki?.coverage});
}
export function mediaFor(extras,questId,objectiveId){
 const chapter=extras?.story?.tasks?.find(t=>t.id===questId),all=extras?.media?.quests?.[questId]?.gallery||chapter?.gallery||[];
 const exact=objectiveId?all.filter(image=>image.objectiveIds?.includes(objectiveId)):[];
 return {images:exact.length?exact:all,association:exact.length?'objective':'quest'};
}
