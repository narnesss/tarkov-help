import {contentBundleResponse} from './content-client.mjs?v=lazy-20261016';
import {json} from './guide-common.mjs?v=lazy-20261016';

export const PUBLIC_BUNDLES={
  'quest-ui':[['guide/quest-choices.json',{}],['guide/quest-map-index.json'],['guide/quest-reward-support.json',{}],['guide/ticket-map.json'],['guide/quest-conditions.json',{quests:{}}]],
  'quest-extras':[
    ['guide/quest-media.json'],['guide/story.json'],['guide/quest-conditions.json',{quests:{}}],
    ['guide/objective-patches.json',{}],['guide/media-localization.json',{}],['guide/wiki-quests.json',{quests:{}}],
    ['guide/gunsmith.json',{quests:{}}],['guide/catalog-update.json'],['guide/quest-map-index.json'],
    ['guide/quest-choices.json',{}],['guide/quest-reward-support.json',{}],['guide/quest-patch-1150.json',{}],
    ['guide/ticket-map.json'],['guide/quest-content.json'],
  ],
  'raid-base':[
    ['raid/maps.json'],['raid/item-names.json',{}],['raid/document-calibration.json'],
    ['raid/quest-index.json'],['guide/btr.json'],['raid/loose-items.json'],
  ],
};

// Сохраняем порядок и прежние резервные значения для статического экспорта.
export async function loadContentBundle(name,{signal,bundle=contentBundleResponse,read=json}={}){
  const entries=PUBLIC_BUNDLES[name];if(!entries)throw Error('Неизвестный набор данных');
  const response=await bundle(name,signal);
  if(response){
    try{
      const payload=await response.json();
      if(entries.every(([path])=>payload?.files?.[path]&&typeof payload.files[path]==='object'))return entries.map(([path])=>payload.files[path]);
    }catch(error){if(signal?.aborted)throw error;}
  }
  return Promise.all(entries.map(([path,fallback])=>read('data/'+path,signal).catch(error=>{
    if(signal?.aborted||fallback===undefined)throw error;return structuredClone(fallback);
  })));
}
