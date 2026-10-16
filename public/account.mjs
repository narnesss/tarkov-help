import {profileStore} from './profile-store.mjs';
import {json,el,action} from './guide-common.mjs?v=lazy-20261016';
import {siteHref} from './site-routes.mjs?v=release-20261016';
import {resolveGameMode} from './game-mode.mjs';
import {accountSummary} from './account-model.mjs';
import {passwordError} from './password-policy.mjs';
import {applyPlayerProfile} from './profile-identity.mjs';
import {formFeedback,fieldError} from './form-feedback.mjs?v=feedback-2';
import {configureLegal,showLegal,collectLegal,showLegalFailure} from './legal-consent.mjs?v=release-20261016';

const $=id=>document.getElementById(id),params=new URLSearchParams(location.search);
let mode=resolveGameMode(params.get('mode'),profileStore.read('kord-atlas-game-mode')),view='login',busy=false,request=0;
const data=new Map();
const authFeedback=formFeedback($('account-form'),{username:$('account-username-error'),password:$('account-password-error'),confirmation:$('account-password-error')});
const resetFeedback=formFeedback($('account-recovery-reset'),{code:$('account-recovery-code-error'),password:$('account-recovery-password-error'),password_confirmation:$('account-recovery-password-error')});
const emailFeedback=formFeedback($('account-recovery-request'),{email:$('account-recovery-email-error')});
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
$('account-email').hidden=!profileStore.profile.authenticated;
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
  for(const button of document.querySelectorAll('[data-auth-view]'))button.setAttribute('aria-pressed',String(button.dataset.authView===view));
  $('account-submit').textContent=view==='register'?'Создать аккаунт':'Войти';
  const form=$('account-form');form.elements.password.autocomplete=view==='register'?'new-password':'current-password';
  form.elements.confirmation.required=view==='register';form.elements.confirmation.disabled=view!=='register';
  $('account-confirm-label').hidden=view!=='register';$('account-password-help').hidden=view!=='register';
  showLegal($('account-register-legal'),view==='register');
  showLegal($('account-oauth-legal'),view==='login'&&!profileStore.profile.authenticated);
  const url=new URL(location.href);url.searchParams.set('view',view);history.replaceState(null,'',url);
}
for(const button of document.querySelectorAll('[data-auth-view]'))button.onclick=()=>setView(button.dataset.authView);
$('account-forgot').onclick=()=>setView('reset');
$('account-recovery-back').onclick=()=>setView('login');
let recovery=null,recoveryAttempts=0;
function renderRecovery(){
  const now=Math.floor(Date.now()/1000),cooldown=Math.max(0,(recovery?.resendAt??0)-now);
  if(recovery&&recovery.expiresAt<=now){recovery=null;if(view==='reset')$('account-auth-feedback').textContent='Срок кода истёк. Запроси новый код восстановления.';}
  $('account-recovery-send').disabled=!profileStore.server||busy||cooldown>0;
  $('account-recovery-send').textContent=cooldown>0?'Повторить через '+cooldown+' с':recovery?'Отправить новый код':'Получить код восстановления';
  $('account-recovery-reset').hidden=!recovery;
  $('account-recovery-confirm').disabled=busy||recoveryAttempts>=5;
}
async function recover(callback,feedback,fallback){
  await run(async()=>{try{$('account-auth-feedback').textContent='';await callback();}catch(error){if(!showFormError(feedback,error,fallback))$('account-auth-feedback').textContent=error.message;}});
  renderRecovery();
}
$('account-recovery-request').onsubmit=event=>{event.preventDefault();recover(async()=>{
  const response=await profileStore.api('/api/auth/password/request',{method:'POST',body:JSON.stringify({email:$('account-recovery-request').elements.email.value.trim()})});
  recovery=response;recoveryAttempts=0;$('account-recovery-reset').reset();$('account-auth-feedback').textContent=response.message;renderRecovery();
  $('account-recovery-reset').elements.code.focus();
},emailFeedback,['email']);};
$('account-recovery-request').elements.email.addEventListener('input',()=>{
  if(recovery){recovery=null;$('account-recovery-reset').reset();$('account-auth-feedback').textContent='';renderRecovery();}
});
$('account-recovery-reset').onsubmit=event=>{event.preventDefault();recover(async()=>{
  if(!recovery||recovery.expiresAt<=Date.now()/1000)throw fieldError('Запроси новый код восстановления.','code');
  const form=$('account-recovery-reset'),password=form.elements.password.value,confirmation=form.elements.password_confirmation.value;
  const policyError=passwordError(password);if(policyError)throw fieldError(policyError,'password');
  if(password!==confirmation)throw fieldError('Пароли не совпадают.','password','password_confirmation');
  try{
    const response=await profileStore.api('/api/auth/password/reset',{method:'POST',body:JSON.stringify({challenge_id:recovery.challengeId,
      code:form.elements.code.value.trim(),password,password_confirmation:confirmation})});
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
async function saveName(name){
  if(!await profileStore.flush())throw new Error('Сначала сохрани последние изменения профиля.');
  const response=await profileStore.api('/api/auth/profile',{method:'POST',body:JSON.stringify({display_name:name})});
  applyPlayerProfile(profileStore,response);renderIdentity();globalThis.dispatchEvent(new Event('wiki:profile'));notify('Имя сохранено.');
}
$('account-name-form').onsubmit=event=>{event.preventDefault();run(()=>saveName($('account-name-form').elements.display_name.value.trim()));};
let emailState=null,emailLoaded=false;
function renderEmail(state){
  emailState=state;
  const pending=state.pending,now=Math.floor(Date.now()/1000),cooldown=Math.max(0,(pending?.resendAt??0)-now);
  $('account-email-status').textContent=(state.email?'Подтверждён: '+state.email+'. ':'Подтверждённая почта пока не указана. ')
    +(pending?'Код отправлен на '+pending.email+'. Осталось попыток: '+pending.attemptsLeft+'.':'');
  if(!state.enabled)$('account-email-status').textContent+=' Отправка писем временно недоступна.';
  if(!emailLoaded){$('account-email-form').elements.email.value=pending?.email??state.email??'';emailLoaded=true;}
  updateEmailSend();
  $('account-email-send').disabled=!state.enabled||cooldown>0;
  $('account-email-send').textContent=cooldown>0?'Повторить через '+cooldown+' с':pending?'Отправить новый код':'Отправить код';
  $('account-email-code-form').hidden=!pending;
  $('account-email-confirm').disabled=!pending||pending.attemptsLeft===0;
}
function updateEmailSend(){
  const address=$('account-email-form').elements.email.value.trim().toLowerCase();
  $('account-email-send').hidden=!!emailState?.email&&address===emailState.email;
}
$('account-email-form').elements.email.addEventListener('input',updateEmailSend);
async function loadEmail(){
  if(!profileStore.profile.authenticated||!profileStore.server)return;
  try{renderEmail(await profileStore.api('/api/auth/email'));}
  catch{$('account-email-status').textContent='Не удалось загрузить настройки почты. Обнови страницу позже.';}
}
$('account-email-form').onsubmit=event=>{event.preventDefault();run(async()=>{
  renderEmail(await profileStore.api('/api/auth/email/send',{method:'POST',body:JSON.stringify({email:$('account-email-form').elements.email.value.trim()})}));
  $('account-email-code-form').elements.code.value='';notify(emailState.sent?'Код отправлен. Введи его из письма.':'Этот e-mail уже подтверждён.');
});};
$('account-email-code-form').onsubmit=event=>{event.preventDefault();run(async()=>{
  if(!emailState?.pending)throw new Error('Запроси новый код подтверждения.');
  try{
    renderEmail(await profileStore.api('/api/auth/email/verify',{method:'POST',body:JSON.stringify({challenge_id:emailState.pending.challengeId,code:$('account-email-code-form').elements.code.value.trim()})}));
    $('account-email-code-form').elements.code.value='';notify('E-mail подтверждён. Аккаунт и прогресс сохранены.');
  }finally{await loadEmail();}
});};
setInterval(()=>{if(emailState?.pending){if(emailState.pending.expiresAt<=Date.now()/1000)emailState={...emailState,pending:null};renderEmail(emailState);}},1000);
loadEmail();
for(const button of document.querySelectorAll('[data-oauth]'))button.onclick=()=>run(async()=>{
  if(!await profileStore.flush())throw new Error('Сначала сохрани последние изменения профиля.');
  const consent=collectLegal($(profileStore.profile.authenticated?'account-link-legal':view==='register'?'account-register-legal':'account-oauth-legal'));
  const result=await profileStore.api('/api/auth/oauth/'+button.dataset.oauth+'/start',{method:'POST',body:JSON.stringify(consent)});
  const target=new URL(result.url);if(target.protocol!=='https:'||!['accounts.google.com','discord.com'].includes(target.hostname))throw new Error('Не удалось открыть сервис входа.');
  location.assign(target.href);
},error=>showLegalFailure($(profileStore.profile.authenticated?'account-link-legal':view==='register'?'account-register-legal':'account-oauth-legal'),error));
async function providers(){
  if(!profileStore.server){$('account-submit').disabled=true;$('account-provider-status').textContent='Вход в аккаунт сейчас недоступен. Можно продолжить с локальным профилем.';return;}
  try{
    const {providers,legal}=await profileStore.api('/api/auth/options');configureLegal(legal);
    showLegal($('account-register-legal'),view==='register');showLegal($('account-oauth-legal'),view==='login'&&!profileStore.profile.authenticated);showLegal($('account-link-legal'),profileStore.profile.authenticated);
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
