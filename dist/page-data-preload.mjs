import {preloadJson} from './guide-common.mjs?v=media-paths-20261017';
import {pageParams} from './site-routes.mjs?v=page-content-20261017';
import {preloadPageView} from './page-data.mjs?v=media-paths-20261017';

// Загружается только публичный набор. Профиль и прогресс подтверждаются отдельно до запуска UI.
export function preloadPageData(page,{params=pageParams(),preload=(path,options)=>options?preloadPageView(path,options):preloadJson(path)}={}){
  const mode=params.get('mode');if(!['pve','regular'].includes(mode))return;
  if(page==='quests')preload(`data/guide/quests-${mode}.json`,params.has('quest')?{view:'detail',id:params.get('quest')}:{view:'catalog'});
  else if(page==='reference'&&['items','ammo'].includes(params.get('tab')||'items'))preload(`data/guide/items-${mode}.json`,params.has('item')?{view:'detail',id:params.get('item')}:{view:'catalog'});
  else if(page==='raid'){
    const map=params.get('map');if(map&&/^[a-z][a-z-]{0,50}$/.test(map))preload(`data/raid/${mode==='pve'?'pve/':''}${map}.json`);
  }
}
