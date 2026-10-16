import {preloadJson} from './guide-common.mjs?v=lazy-20261016';
import {pageParams} from './site-routes.mjs?v=release-20261016';
import {preloadPageView} from './page-data.mjs?v=lazy-20261016';

// Загружается только публичный набор. Профиль и прогресс подтверждаются отдельно до запуска UI.
export function preloadPageData(page,{params=pageParams(),preload=(path,view)=>view?preloadPageView(path,{view}):preloadJson(path)}={}){
  const mode=params.get('mode');if(!['pve','regular'].includes(mode))return;
  if(page==='quests')preload(`data/guide/quests-${mode}.json`,'catalog');
  else if(page==='reference')preload(`data/guide/items-${mode}.json`,'catalog');
  else if(page==='raid'){
    const map=params.get('map');if(map&&/^[a-z][a-z-]{0,50}$/.test(map))preload(`data/raid/${mode==='pve'?'pve/':''}${map}.json`);
  }
}
