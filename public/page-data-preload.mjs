import {preloadJson} from './guide-common.mjs?v=release-20261016';
import {pageParams} from './site-routes.mjs?v=release-20261016';

// Загружается только публичный набор. Профиль и прогресс подтверждаются отдельно до запуска UI.
export function preloadPageData(page,{params=pageParams(),preload=preloadJson}={}){
  const mode=params.get('mode');if(!['pve','regular'].includes(mode))return;
  if(page==='quests')preload(`data/guide/quests-${mode}.json`);
  else if(page==='reference')preload(`data/guide/reference-${mode}.json`);
  else if(page==='raid'){
    const map=params.get('map');if(map&&/^[a-z][a-z-]{0,50}$/.test(map))preload(`data/raid/${mode==='pve'?'pve/':''}${map}.json`);
  }
}
