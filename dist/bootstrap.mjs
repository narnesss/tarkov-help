import {profileStore} from './profile-store.mjs?v=page-content-20261017';
import {serverProfiles} from './profile-runtime.mjs?v=page-content-20261017';
import {mountProfile} from './profile-ui.mjs?v=page-content-20261017';
import {observeHeader} from './layout.mjs?v=page-content-20261017';
import {initializeSiteRoutes,siteHref} from './site-routes.mjs?v=page-content-20261017';

async function boot(){
await initializeSiteRoutes();

const runtime = serverProfiles ? 'php' : document.querySelector('meta[name="wiki-runtime"]')?.content || 'static';
const previousProfile=profileStore.profile;
const entry = document.body.dataset.page;
try {
  const initializing=profileStore.initialize(runtime);
  if(['quests','raid','reference'].includes(entry)){
    await import('./page-data-preload.mjs?v=page-content-20261017').then(module=>module.preloadPageData(entry)).catch(()=>{});
  }
  await initializing;
}
catch (error) {
  if (profileStore.profile===previousProfile && profileStore.meta.serverProfile) { profileStore.profile = profileStore.meta.serverProfile; profileStore.memory.clear(); }
  const notice = document.createElement('p'); notice.className = 'profile-offline'; notice.setAttribute('role', 'status');
  notice.textContent = 'Синхронизация временно недоступна. Изменения будут сохранены в этом браузере.';
  document.body.prepend(notice);
}
mountProfile();
observeHeader();
globalThis.addEventListener('storage', event => {
  const change = profileStore.onStorage(event);
  if (change?.reload) location.reload();
  else if (change?.key) globalThis.dispatchEvent(new CustomEvent('wiki:state', {detail: change}));
});
globalThis.addEventListener('online', () => profileStore.initialize(runtime).then(() => location.reload()).catch(() => {}));
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') profileStore.flush(); });
if (entry === 'index' && ['map','point'].some(key=>new URLSearchParams(location.search).has(key))) {
  location.replace(siteHref('documents.html'+location.search+location.hash));
} else {
const modules = {index: './home.mjs?v=page-content-20261017', documents: './app.mjs?v=page-content-20261017', raid: './raid.mjs?v=page-content-20261017', quests: './quests.mjs?v=page-content-20261017', reference: './reference.mjs?v=page-content-20261017', boss: './boss.mjs?v=page-content-20261017', account: './account.mjs?v=page-content-20261017'};
if (modules[entry]) await import(modules[entry]);

}

}
try { await boot(); document.body.dataset.contentState='ready'; }
catch(error){
 document.body.dataset.contentState='error';
 const root=document.getElementById('page-boot-status');
 if(root){root.replaceChildren(document.createTextNode(error.message||'Не удалось загрузить страницу.'));
 const retry=document.createElement('button');retry.className='button';retry.textContent='Повторить';retry.onclick=()=>location.reload();root.append(retry);}
}
