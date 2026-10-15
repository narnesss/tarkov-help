import {profileStore} from './profile-store.mjs';
import {serverProfiles} from './profile-runtime.mjs';
import {mountProfile} from './profile-ui.mjs?v=account-1';
import {observeHeader} from './layout.mjs';
import {initializeSiteRoutes,siteHref} from './site-routes.mjs';

await initializeSiteRoutes();

const runtime = serverProfiles ? 'php' : document.querySelector('meta[name="wiki-runtime"]')?.content || 'static';
try { await profileStore.initialize(runtime); }
catch (error) {
  if (profileStore.meta.serverProfile) { profileStore.profile = profileStore.meta.serverProfile; profileStore.memory.clear(); }
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
const entry = document.body.dataset.page;
if (entry === 'index' && ['map','point'].some(key=>new URLSearchParams(location.search).has(key))) {
  location.replace(siteHref('documents.html'+location.search+location.hash));
} else {
const modules = {index: './home.mjs', documents: './app.mjs', raid: './raid.mjs', quests: './quests.mjs', reference: './reference.mjs', boss: './boss.mjs', account: './account.mjs'};
if (modules[entry]) await import(modules[entry]);

}
