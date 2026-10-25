import {profileStore} from './profile-store.mjs?v=loading-20261018';
import {serverProfiles} from './profile-runtime.mjs?v=page-content-20261017';
import {mountProfile} from './profile-ui.mjs?v=loading-20261018';
import {observeHeader} from './layout.mjs?v=page-content-20261017';
import {initializeSiteRoutes,siteHref} from './site-routes.mjs?v=page-content-20261017';

async function boot(){
await initializeSiteRoutes();

const runtime = serverProfiles ? 'php' : document.querySelector('meta[name="wiki-runtime"]')?.content || 'static';
const previousProfile=profileStore.profile;
const entry = document.body.dataset.page;
try {
  const initializing=profileStore.initialize(runtime);
  const preloading=['quests','raid','reference'].includes(entry)
    ? import('./page-data-preload.mjs?v=480f6db6450df584').then(module=>module.preloadPageData(entry)).catch(()=>{})
    : Promise.resolve();
  await Promise.all([initializing,preloading]);
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
const modules = {'important-items':'./important-items.mjs?v=loading-20261018',index: './home.mjs?v=4db8f9bd69ba8aec', documents: './app.mjs?v=2ef97cfa35520c43', raid: './raid.mjs?v=e7aa86940cf80981', quests: './quests.mjs?v=748793e60499e4db', reference: './reference.mjs?v=a893a6e80fdbb776', boss: './boss.mjs?v=f7c09de938448f61', account: './account.mjs?v=2348bcf26f9bf4b9'};
const pageModule=document.querySelector('link[data-page-module]')?.getAttribute('href')||modules[entry];
if (pageModule) await import(pageModule.startsWith('.')?pageModule:'./'+pageModule);

}

}
try { await boot(); document.body.dataset.contentState='ready'; }
catch(error){
 document.body.dataset.contentState='error';
 const root=document.getElementById('page-boot-status');
 if(root){root.replaceChildren(document.createTextNode('Не удалось загрузить страницу. Проверьте соединение и повторите попытку.'));
 const retry=document.createElement('button');retry.className='button';retry.textContent='Повторить';retry.onclick=()=>location.reload();root.append(retry);}
}
