import {profileStore} from './profile-store.mjs';
import {json,el,action} from './guide-common.mjs';
import {siteHref} from './site-routes.mjs';
import {resolveGameMode} from './game-mode.mjs';
import {accountSummary} from './account-model.mjs';

const $=id=>document.getElementById(id),params=new URLSearchParams(location.search);
let mode=resolveGameMode(params.get('mode'),profileStore.read('kord-atlas-game-mode')),view='login',busy=false,request=0;
const data=new Map();
const notify=text=>{$('account-status').textContent=text;};
async function run(callback){if(busy)return;busy=true;document.body.setAttribute('aria-busy','true');$('account-submit').disabled=true;notify('');try{await callback();}catch(error){notify(error.message);}finally{busy=false;document.body.removeAttribute('aria-busy');$('account-submit').disabled=!profileStore.server;}}
function tools(){globalThis.dispatchEvent(new CustomEvent('wiki:open-profile'));}
for(const button of document.querySelectorAll('[data-profile-tools]'))button.onclick=tools;
$('account-name').textContent=profileStore.profile.name;
$('account-auth').hidden=!!profileStore.profile.authenticated;
$('account-connections').hidden=!profileStore.profile.authenticated;
$('account-logout').hidden=!profileStore.profile.authenticated;
$('account-sync').hidden=!profileStore.server;
$('account-storage').textContent=profileStore.server?(profileStore.profile.authenticated?'Аккаунт · прогресс на сервере':'Гостевой профиль · прогресс на сервере'):'Локальный профиль · в этом браузере';

function setView(next){
  view=next==='register'?'register':'login';
  for(const button of document.querySelectorAll('[data-auth-view]'))button.setAttribute('aria-pressed',String(button.dataset.authView===view));
  $('account-submit').textContent=view==='register'?'Создать аккаунт':'Войти';
  const form=$('account-form');form.elements.password.autocomplete=view==='register'?'new-password':'current-password';
  form.elements.confirmation.required=view==='register';form.elements.confirmation.disabled=view!=='register';
  $('account-confirm-label').hidden=view!=='register';$('account-password-help').hidden=view!=='register';
  const url=new URL(location.href);url.searchParams.set('view',view);history.replaceState(null,'',url);
}
for(const button of document.querySelectorAll('[data-auth-view]'))button.onclick=()=>setView(button.dataset.authView);
$('account-form').onsubmit=event=>{event.preventDefault();run(async()=>{
  const form=event.currentTarget??$('account-form'),username=form.elements.username.value.trim(),password=form.elements.password.value;
  if(view==='register'){
    if(!/^[\p{L}\p{N}_-]{3,32}$/u.test(username))throw new Error('Логин: 3–32 буквы, цифры, дефис или _.');
    const bytes=new TextEncoder().encode(password).length;
    if(bytes<12||bytes>72)throw new Error('Пароль должен занимать от 12 до 72 байт.');
    if(password!==form.elements.confirmation.value)throw new Error('Пароли не совпадают.');
  }
  await profileStore.authenticate(view,{username,password});location.replace(siteHref('account.html?mode='+mode));
});};
$('account-logout').onclick=()=>run(async()=>{await profileStore.authenticate('logout');location.replace(siteHref('account.html?view=login'));});
$('account-sync').onclick=()=>run(async()=>{if(!await profileStore.flush())throw new Error('Не удалось сохранить изменения. Повтори синхронизацию позже.');notify('Прогресс сохранён на сервере.');});
for(const button of document.querySelectorAll('[data-oauth]'))button.onclick=()=>run(async()=>{
  if(!await profileStore.flush())throw new Error('Сначала сохрани последние изменения профиля.');
  const result=await profileStore.api('/api/auth/oauth/'+button.dataset.oauth+'/start',{method:'POST',body:'{}'});
  const target=new URL(result.url);if(target.protocol!=='https:'||!['accounts.google.com','discord.com'].includes(target.hostname))throw new Error('Не удалось открыть сервис входа.');
  location.assign(target.href);
});
async function providers(){
  if(!profileStore.server){$('account-submit').disabled=true;$('account-provider-status').textContent='Вход в аккаунт сейчас недоступен. Можно продолжить с локальным профилем.';return;}
  try{
    const {providers}=await profileStore.api('/api/auth/options');
    for(const provider of providers)for(const button of document.querySelectorAll(`[data-oauth="${provider.id}"]`)){
      button.disabled=!provider.enabled||provider.linked;
      button.title=provider.linked?'Уже подключён':provider.enabled?'':'Этот способ входа пока недоступен';
      if(provider.linked)button.textContent=(provider.id==='google'?'Google':'Discord')+' подключён';
    }
    const unavailable=providers.filter(p=>!p.enabled).map(p=>p.id==='google'?'Google':'Discord');
    const text=unavailable.length?'Вход через '+unavailable.join(' и ')+' пока недоступен.':'';
    $('account-provider-status').textContent=text;$('account-connections-status').textContent=text;
  }catch{$('account-provider-status').textContent='Вход через Google и Discord временно недоступен.';}
}

async function render(){
  const ticket=++request,current=mode;
  for(const button of document.querySelectorAll('[data-account-mode]'))button.setAttribute('aria-pressed',String(button.dataset.accountMode===mode));
  $('account-mode-label').textContent='ПРОГРЕСС '+(mode==='pve'?'PVE':'PVP');
  $('account-summary').replaceChildren(el('p','Загружаем сводку…'));$('account-active').textContent='Загрузка…';$('account-unlocks').textContent='Загрузка…';$('account-level').textContent='';
  try{
    if(!data.has(current))data.set(current,Promise.all([json(`data/guide/quests-${current}.json`),json(`data/guide/reference-${current}.json`)]).catch(error=>{data.delete(current);throw error;}));
    const [quests,reference]=await data.get(current);if(ticket!==request)return;
    const summary=accountSummary({tasks:quests.tasks,hideout:reference.hideout,achievements:reference.achievements},profileStore.read(`kord-atlas-quests-${current}-v1`),profileStore.read('kord-hideout-'+current),profileStore.read('kord-achievements-'+current));
    $('account-level').textContent='Уровень персонажа: '+summary.level;
    const root=$('account-summary');root.replaceChildren();
    for(const [key,title,caption,href] of [['quests','Квесты','выполнено','quests.html?section=all'],['hideout','Убежище','уровней построено','reference.html?tab=hideout'],['achievements','Достижения','получено','reference.html?tab=achievements']]){
      const item=summary[key],card=el('article','','account-stat'),top=el('div','','account-stat-heading');top.append(el('h3',title),el('span',Math.round(item.done/Math.max(1,item.total)*100)+'%'));
      const value=el('p','','account-stat-value');value.append(el('strong',String(item.done)),document.createTextNode(' / '+item.total));
      const progress=el('progress');progress.max=Math.max(1,item.total);progress.value=item.done;progress.setAttribute('aria-label',title+': '+item.done+' из '+item.total);
      const link=el('a','Открыть раздел →','account-link');link.href=siteHref(href+'&mode='+current);
      card.append(top,value,el('p',caption,'profile-help'),progress,link);root.append(card);
    }
    const active=$('account-active');active.replaceChildren();
    for(const task of summary.quests.active.slice(0,6)){const link=el('a',task.name,'account-row');link.href=siteHref(`quests.html?quest=${encodeURIComponent(task.id)}&mode=${current}`);active.append(link);}
    if(!summary.quests.active.length)active.append(el('p','Пока нет активных квестов. Открой журнал и отметь начатые задания.','profile-help'));
    if(summary.quests.active.length>6){const link=el('a','Все активные квесты →','account-link');link.href=siteHref(`quests.html?section=all&status=active&mode=${current}`);active.append(link);}
    const unlocks=$('account-unlocks');unlocks.replaceChildren();
    for(const level of summary.hideout.unlocked.slice(0,3))unlocks.append(el('p',`✓ ${level.stationName} · уровень ${level.level}`,'account-row'));
    for(const achievement of summary.achievements.unlocked.slice(0,3))unlocks.append(el('p','★ '+achievement.name,'account-row'));
    if(!unlocks.childNodes.length)unlocks.append(el('p','Отметь построенные уровни убежища и полученные достижения в справочнике — они появятся здесь.','profile-help'));
  }catch(error){console.warn('Сводка профиля: '+error.message);if(ticket!==request)return;$('account-summary').replaceChildren(el('p','Не удалось загрузить каталог. Прогресс сохранён.'),action('Повторить',render));$('account-active').textContent='Список временно недоступен.';$('account-unlocks').textContent='Список временно недоступен.';}
}
for(const button of document.querySelectorAll('[data-account-mode]'))button.onclick=()=>{mode=button.dataset.accountMode;profileStore.write('kord-atlas-game-mode',mode);const url=new URL(location.href);url.searchParams.set('mode',mode);history.replaceState(null,'',url);render();};
globalThis.addEventListener('wiki:state',render);
profileStore.subscribe(event=>{if(event.state==='saved'&&event.key!=='kord-atlas-game-mode')render();});

const oauth=params.get('oauth'),code=params.get('code'),state=params.get('state'),oauthError=params.get('error');
if(oauth){
  history.replaceState(null,'',siteHref('account.html'));
  if(oauthError)notify('Вход отменён. Можно выбрать другой способ или попробовать снова.');
  else if(['google','discord'].includes(oauth)&&code&&state){
    await run(async()=>{await profileStore.authenticate('oauth/'+oauth+'/finish',{code,state});location.replace(siteHref('account.html'));});
  }else notify('Не удалось завершить вход. Начни его ещё раз.');
}else if(!profileStore.profile.authenticated)setView(params.get('view'));
providers();render();
