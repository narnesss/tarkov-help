import {contentBundleResponse} from './content-client.mjs?v=page-content-20261017';

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

// Порядок файлов задаёт контракт backend; ошибки не подменяются локальными снимками.
export async function loadContentBundle(name,{signal,bundle=contentBundleResponse,scope={}}={}){
  const entries=PUBLIC_BUNDLES[name];if(!entries)throw Error('Неизвестный набор данных');
  const response=await bundle(name,signal,globalThis.fetch,scope);
  if(response){
    try{
      const payload=await response.json();
      if(entries.every(([path])=>payload?.files?.[path]&&typeof payload.files[path]==='object'))return entries.map(([path])=>payload.files[path]);
    }catch(error){if(signal?.aborted)throw error;}
  }
  throw Error('Сервер вернул неполный набор данных. Повторите попытку.');
}
