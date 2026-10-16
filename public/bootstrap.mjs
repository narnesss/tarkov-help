import {profileStore} from './profile-store.mjs';
import {serverProfiles} from './profile-runtime.mjs';
import {mountProfile} from './profile-ui.mjs?v=session-20261016';
import {observeHeader} from './layout.mjs';
import {initializeSiteRoutes,siteHref} from './site-routes.mjs?v=release-20261016';

await initializeSiteRoutes();

const runtime = serverProfiles ? 'php' : document.querySelector('meta[name="wiki-runtime"]')?.content || 'static';
const previousProfile=profileStore.profile;
const entry = document.body.dataset.page;
try {
  const initializing=profileStore.initialize(runtime);
  if(['quests','raid','reference'].includes(entry)){
    import('./page-data-preload.mjs?v=lazy-20261016').then(module=>module.preloadPageData(entry)).catch(()=>{});
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
const modules = {index: './home.mjs?v=lazy-20261016', documents: './app.mjs?v=mobile-documents-20261016', raid: './raid.mjs?v=mobile-raid-20261016b', quests: './quests.mjs?v=quest-walkthrough-20261016', reference: './reference.mjs?v=mobile-traders-20261017', boss: './boss.mjs?v=lazy-20261016', account: './account.mjs?v=account-20261016b'};
if (modules[entry]) await import(modules[entry]);

}
