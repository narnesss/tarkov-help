import {profileFields} from './profile-fields.mjs?v=profile-details-20261017';
import {pageDataset} from './page-data.mjs?v=page-content-20261017';
import {profileStore} from './profile-store.mjs?v=page-content-20261017';
import {el,action} from './guide-common.mjs?v=page-content-20261017';
import {siteHref} from './site-routes.mjs?v=page-content-20261017';
import {resolveGameMode} from './game-mode.mjs?v=page-content-20261017';
import {accountSummary} from './account-model.mjs?v=page-content-20261017';
import {passwordError} from './password-policy.mjs?v=page-content-20261017';
import {applyPlayerProfile} from './profile-identity.mjs?v=page-content-20261017';
import {formFeedback,fieldError} from './form-feedback.mjs?v=page-content-20261017';
import {configureLegal,showLegal,collectLegal,showLegalFailure} from './legal-consent.mjs?v=page-content-20261017';

const $=id=>document.getElementById(id),params=new URLSearchParams(location.search);
let mode=resolveGameMode(params.get('mode'),profileStore.read('kord-atlas-game-mode')),view='login',busy=false,request=0;
const data=new Map();
const authFeedback=formFeedback($('account-form'),{username:$('account-username-error'),password:$('account-password-error'),confirmation:$('account-password-error')});
const resetFeedback=formFeedback($('account-recovery-reset'),{code:$('account-recovery-code-error'),password:$('account-recovery-password-error'),password_confirmation:$('account-recovery-password-error')});
const emailFeedback=formFeedback($('account-recovery-request'),{email:$('account-recovery-email-error')});
const profileFeedback=formFeedback($('account-name-form'),Object.fromEntries(['display_name','phone','country','city','gender','age'].map(field=>[field,$('account-'+(field==='display_name'?'display-name':field)+'-error')])));
let details=null,detailsLoaded=false;
function showFormError(feedback,error,fallback){
  const names=error.fields??Object.keys(error.data?.errors??{});
  return feedback.show(error.message,names.length?names:fallback);
}
const notify=text=>{$('account-status').textContent=text;};
async function run(callback,onError){if(busy)return;busy=true;document.body.setAttribute('aria-busy','true');$('account-submit').disabled=true;notify('');try{await callback();}catch(error){if(!onError?.(error))notify(error.message);}finally{busy=false;document.body.removeAttribute('aria-busy');$('account-submit').disabled=!profileStore.server;}}
function tools(){globalThis.dispatchEvent(new CustomEvent('wiki:open-profile'));}
for(const button of document.querySelectorAll('[data-profile-tools]'))button.onclick=tools;
function renderIdentity(){
$('account-name').textContent=profileStore.profile.name;
$('account-settings').hidden=!profileStore.profile.authenticated;
$('account-name-form').elements.display_name.value=profileStore.profile.name;
$('account-avatar').textContent=profileStore.profile.name.slice(0,1).toUpperCase();
const avatar=profileStore.profile.avatar;
if(typeof avatar==='string'&&avatar){try{
  const url=new URL(avatar);
  if(url.protocol==='https:'&&(url.hostname==='cdn.discordapp.com'||url.hostname==='googleusercontent.com'||url.hostname.endsWith('.googleusercontent.com'))){
    const image=el('img');image.alt='';image.width=64;image.height=64;image.referrerPolicy='no-referrer';
    image.onerror=()=>{$('account-avatar').textContent=profileStore.profile.name.slice(0,1).toUpperCase();};
    image.src=url.href;$('account-avatar').replaceChildren(image);
  }
}catch{/* Показываем инициалы, если сохранённый адрес больше не подходит. */}}
$('account-auth').hidden=!!profileStore.profile.authenticated;
$('account-connections').hidden=!profileStore.profile.authenticated;
$('account-logout').hidden=!profileStore.profile.authenticated;
$('account-sync').hidden=!profileStore.server;
$('account-storage').textContent=profileStore.server?(profileStore.profile.authenticated?'Аккаунт · прогресс на сервере':'Гостевой профиль · прогресс на сервере'):'Локальный профиль · в этом браузере';

}
renderIdentity();

function setView(next){
  view=['register','reset'].includes(next)?next:'login';
  authFeedback.clear();resetFeedback.clear();emailFeedback.clear();
  $('account-auth-login').hidden=view==='reset';$('account-recovery').hidden=view!=='reset';
  $('account-forgot').hidden=view!=='login';$('account-auth-feedback').textContent='';
  $('account-recovery-feedback').textContent='';
  $('account-recovery-back').textContent=profileStore.profile.authenticated?'Вернуться к профилю':'Вернуться ко входу';
  for(const button of document.querySelectorAll('[data-auth-view]'))button.setAttribute('aria-pressed',String(button.dataset.authView===view));
  $('account-submit').textContent=view==='register'?'Создать аккаунт':'Войти';
  const form=$('account-form');form.elements.password.autocomplete=view==='register'?'new-password':'current-password';
  form.elements.confirmation.required=view==='register';form.elements.confirmation.disabled=view!=='register';
  $('account-confirm-label').hidden=view!=='register';$('account-password-help').hidden=view!=='register';
  showLegal($('account-register-legal'),view==='register');
  const url=new URL(location.href);url.searchParams.set('view',view);history.replaceState(null,'',url);
}
for(const button of document.querySelectorAll('[data-auth-view]'))button.onclick=()=>setView(button.dataset.authView);
$('account-forgot').onclick=()=>setView('reset');
$('account-recovery-back').onclick=()=>setView('login');
$('account-password-reset').onclick=()=>{
  if(!details?.passwordResetAvailable)return;
  setView('reset');$('account-recovery-request').elements.email.value=details.email;
  $('account-recovery-request').elements.email.readOnly=true;
  $('account-recovery').scrollIntoView({behavior:'smooth',block:'start'});
};
let recovery=null,recoveryAttempts=0;
function renderRecovery(){
  const now=Math.floor(Date.now()/1000),cooldown=Math.max(0,(recovery?.resendAt??0)-now);
  if(recovery&&recovery.expiresAt<=now){recovery=null;if(view==='reset')$('account-recovery-feedback').textContent='Срок кода истёк. Запроси новый код восстановления.';}
  $('account-recovery-send').disabled=!profileStore.server||busy||cooldown>0;
  $('account-recovery-send').textContent=cooldown>0?'Повторить через '+cooldown+' с':recovery?'Отправить новый код':'Получить код восстановления';
  $('account-recovery-reset').hidden=!recovery;
  $('account-recovery-confirm').disabled=busy||recoveryAttempts>=5;
}
async function recover(callback,feedback,fallback){
  await run(async()=>{try{$('account-recovery-feedback').textContent='';await callback();}catch(error){if(!showFormError(feedback,error,fallback))$('account-recovery-feedback').textContent=error.message;}});
  renderRecovery();
}
$('account-recovery-request').onsubmit=event=>{event.preventDefault();recover(async()=>{
  const response=await profileStore.api('/api/auth/password/request',{method:'POST',body:JSON.stringify({email:$('account-recovery-request').elements.email.value.trim()})});
  recovery=response;recoveryAttempts=0;$('account-recovery-reset').reset();$('account-recovery-feedback').textContent=response.message;renderRecovery();
  $('account-recovery-reset').elements.code.focus();
},emailFeedback,['email']);};
$('account-recovery-request').elements.email.addEventListener('input',()=>{
  if(recovery){recovery=null;$('account-recovery-reset').reset();$('account-recovery-feedback').textContent='';renderRecovery();}
});
$('account-recovery-reset').onsubmit=event=>{event.preventDefault();recover(async()=>{
  if(!recovery||recovery.expiresAt<=Date.now()/1000)throw fieldError('Запроси новый код восстановления.','code');
  const form=$('account-recovery-reset'),password=form.elements.password.value,confirmation=form.elements.password_confirmation.value;
  const policyError=passwordError(password);if(policyError)throw fieldError(policyError,'password');
  if(password!==confirmation)throw fieldError('Пароли не совпадают.','password','password_confirmation');
  try{
    if(profileStore.profile.authenticated&&!await profileStore.flush())throw new Error('Сначала сохрани последние изменения прогресса.');
    const response=await profileStore.api('/api/auth/password/reset',{method:'POST',body:JSON.stringify({challenge_id:recovery.challengeId,
      code:form.elements.code.value.trim(),password,password_confirmation:confirmation})});
    if(profileStore.profile.authenticated){
      profileStore.setRaw('eft-wiki:identity-change',{id:crypto.randomUUID(),at:Date.now()});
      location.replace(siteHref('account.html?view=login'));return;
    }
    recovery=null;form.reset();setView('login');$('account-form').elements.username.value=response.username;$('account-form').elements.password.value='';
    $('account-auth-feedback').textContent=response.message;$('account-form').elements.password.focus();
  }catch(error){
    if(error.status===422&&!error.data?.errors&&error.message.startsWith('Код неверен')){
      recoveryAttempts++;form.elements.code.value='';
      if(recoveryAttempts>=5)error.message='Пять попыток исчерпаны. Запроси новый код восстановления.';
    }
    throw error;
  }
},resetFeedback,['code']);};
setInterval(renderRecovery,1000);renderRecovery();
$('account-form').onsubmit=event=>{event.preventDefault();run(async()=>{
  const form=event.currentTarget??$('account-form'),username=form.elements.username.value.trim(),password=form.elements.password.value;
  if(view==='register'){
    if(!/^[\p{L}\p{N}_-]{3,32}$/u.test(username))throw fieldError('Логин: 3–32 буквы, цифры, дефис или _.','username');
    const policyError=passwordError(password,username);if(policyError)throw fieldError(policyError,'password');
    if(password!==form.elements.confirmation.value)throw fieldError('Пароли не совпадают.','password','confirmation');
  }
  const consent=view==='register'?collectLegal($('account-register-legal')):{};
  await profileStore.authenticate(view,{username,password,...consent});location.replace(siteHref('account.html?mode='+mode));
},error=>showLegalFailure($('account-register-legal'),error)||showFormError(authFeedback,error,view==='register'?['password']:['password','username']));};
$('account-logout').onclick=()=>run(async()=>{await profileStore.authenticate('logout');location.replace(siteHref('account.html?view=login'));});
$('account-sync').onclick=()=>run(async()=>{if(!await profileStore.flush())throw new Error('Не удалось сохранить изменения. Повтори синхронизацию позже.');notify('Прогресс сохранён на сервере.');});
async function loadDetails(){
  if(!profileStore.profile.authenticated)return;
  details=await profileStore.api('/api/auth/profile');detailsLoaded=true;
  const form=$('account-name-form');
  for(const field of ['phone','country','city','gender','age'])form.elements[field].value=details[field]??'';
  $('account-contact-email').textContent=details.email??'Не указан';
  const date=value=>value?new Intl.DateTimeFormat('ru-RU',{dateStyle:'long',timeStyle:'short'}).format(new Date(Number(value)*1000)):'Нет данных';
  $('account-registered').textContent=date(details.registeredAt);$('account-last-active').textContent=date(details.lastActiveAt);
  $('account-profile-fields').disabled=false;$('account-password-reset').disabled=!details.passwordResetAvailable;
  $('account-password-reset-help').textContent=details.passwordResetAvailable?'Код придёт на подтверждённую почту. После смены пароля потребуется войти заново. Аккаунт и прогресс сохранятся.':'Для сброса пароля нужна подтверждённая контактная почта. Если её нет, войди через подключённый Google или Discord либо обратись в поддержку.';
}
async function saveProfile(){
  if(!detailsLoaded)throw new Error('Данные профиля ещё не загружены.');
  const values=profileFields(Object.fromEntries(new FormData($('account-name-form'))));
  values.profile_version=details.version;
  if(!await profileStore.flush())throw new Error('Сначала сохрани последние изменения профиля.');
  const response=await profileStore.api('/api/auth/profile',{method:'POST',body:JSON.stringify(values)});
  applyPlayerProfile(profileStore,response);renderIdentity();await loadDetails();globalThis.dispatchEvent(new Event('wiki:profile'));notify('Профиль сохранён.');
}
$('account-name-form').onsubmit=event=>{event.preventDefault();run(saveProfile,error=>error.status!==409&&showFormError(profileFeedback,error,['display_name']));};
for(const button of document.querySelectorAll('[data-oauth]'))button.onclick=()=>run(async()=>{
  if(!await profileStore.flush())throw new Error('Сначала сохрани последние изменения профиля.');
  const consent=!profileStore.profile.authenticated&&view==='register'?collectLegal($('account-register-legal')):{};
  const result=await profileStore.api('/api/auth/oauth/'+button.dataset.oauth+'/start',{method:'POST',body:JSON.stringify(consent)});
  const target=new URL(result.url);if(target.protocol!=='https:'||!['accounts.google.com','discord.com'].includes(target.hostname))throw new Error('Не удалось открыть сервис входа.');
  location.assign(target.href);
},error=>!profileStore.profile.authenticated&&view==='register'&&showLegalFailure($('account-register-legal'),error));
async function providers(){
  if(!profileStore.server){$('account-submit').disabled=true;$('account-provider-status').textContent='Вход в аккаунт сейчас недоступен. Можно продолжить с локальным профилем.';return;}
  try{
    const {providers,legal}=await profileStore.api('/api/auth/options');configureLegal(legal);
    showLegal($('account-register-legal'),view==='register'&&!profileStore.profile.authenticated);
    for(const provider of providers)for(const button of document.querySelectorAll(`[data-oauth="${provider.id}"]`)){
      button.disabled=!provider.enabled;
      button.title=provider.linked?'Этот способ входа уже подключён':provider.enabled?'':'Этот способ входа пока недоступен';
      if(provider.linked&&button.closest('#account-connections')){button.textContent=(provider.id==='google'?'Google':'Discord')+' подключён';button.disabled=true;}
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
    if(!data.has(current))data.set(current,Promise.all([pageDataset(`account-quests-${current}`,`data/guide/quests-${current}.json`),pageDataset(`account-hideout-${current}`,`data/guide/reference-${current}.json`,{section:'hideout'}),pageDataset(`account-achievements-${current}`,`data/guide/reference-${current}.json`,{section:'achievements'})]).then(([quests,hideout,achievements])=>[quests,{hideout:hideout.hideout,achievements:achievements.achievements}]).catch(error=>{data.delete(current);throw error;}));
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
    await run(async()=>{await profileStore.authenticate('oauth/'+oauth+'/finish',{code,state});location.replace(siteHref('account.html'));},error=>{
      if(error.data?.errors?.['legal.registration']){setView('register');$('account-auth-feedback').textContent=error.message;return true;}
      return false;
    });
  }else notify('Не удалось завершить вход. Начни его ещё раз.');
}else if(!profileStore.profile.authenticated)setView(params.get('view'));
providers();render();
loadDetails().catch(()=>notify('Не удалось загрузить данные профиля. Обнови страницу, чтобы повторить.'));
