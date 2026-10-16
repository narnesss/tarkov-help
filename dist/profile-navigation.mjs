// Гостевые действия появляются только после подтверждения состояния сессии.
export function renderProfileNavigation(store,trigger,guestLinks){
  const confirmed=!store.server||store.sessionConfirmed;
  trigger.textContent=!confirmed||store.profile.authenticated?'Личный кабинет':'Мой прогресс';
  trigger.setAttribute('aria-label',confirmed?'Личный кабинет: '+store.profile.name:'Личный кабинет');
  if(confirmed)trigger.removeAttribute('aria-busy');else trigger.setAttribute('aria-busy','true');
  for(const link of guestLinks)link.hidden=!confirmed||!!store.profile.authenticated;
}
