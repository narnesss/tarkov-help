import {passwordError} from './password-policy.mjs?v=policy-1';

// Предварительная проверка помогает заполнению; сервер повторно проверяет каждый POST.
export function playerErrors(values,{creating=false}={}){
  const errors={},username=values.username.trim(),name=values.display_name.trim(),avatar=values.avatar_url.trim(),email=values.email.trim();
  if(!/^[\p{L}\p{N}_-]{3,32}$/u.test(username))errors.username='Логин: 3–32 буквы, цифры, дефис или _.';
  if(!name||[...name].length>64||/[\p{C}<>]/u.test(name))errors.display_name='Имя: от 1 до 64 символов, без HTML и управляющих знаков.';
  if(email&&([...email].length>254||!/^[^\s@]+@[^\s@]+\.[^\s@]+$/u.test(email)))errors.email='Введите корректный e-mail.';
  if(avatar){
    let valid=false;
    try{const url=new URL(avatar);valid=avatar.length<=2048&&avatar.startsWith('https://')&&!url.username&&!url.password&&!url.port&&!/^https:\/\/[^/?#]*:/i.test(avatar)&&!/[\p{C}<>"'\s]/u.test(avatar)
      &&(url.hostname==='cdn.discordapp.com'||url.hostname==='googleusercontent.com'||url.hostname.endsWith('.googleusercontent.com'));}catch{}
    if(!valid)errors.avatar_url='Нужен HTTPS-адрес аватара Google или Discord.';
  }
  if(creating||values.password) {const error=passwordError(values.password,username);if(error)errors.password=error;}
  return errors;
}

if(typeof document!=='undefined'){
  const names=['username','display_name','avatar_url','email','password'];
  const fields=form=>Object.fromEntries(names.map(name=>[name,form.elements.namedItem('player['+name+']')]));
  function validate(form,force=false,changed=null){
    const controls=fields(form);if(!controls.username||!controls.password)return true;
    const errors=playerErrors(Object.fromEntries(names.map(name=>[name,controls[name]?.value??''])),{creating:controls.password.required});
    for(const name of names){
      const input=controls[name];if(!input)continue;
      const error=errors[name]??'';input.setCustomValidity(error);
      if(!force&&input!==changed&&!input.hasAttribute('aria-invalid'))continue;
      const id='player-client-error-'+name;
      let feedback=form.querySelector('#'+id);
      if(!feedback){feedback=document.createElement('div');feedback.id=id;feedback.className='invalid-feedback';feedback.setAttribute('role','alert');input.insertAdjacentElement('afterend',feedback);}
      feedback.textContent=error;feedback.style.display=error?'block':'none';
      input.classList.toggle('is-invalid',!!error);
      if(error)input.setAttribute('aria-invalid','true');else input.removeAttribute('aria-invalid');
      const ids=new Set((input.getAttribute('aria-describedby')??'').split(' ').filter(Boolean));ids.add(id);input.setAttribute('aria-describedby',[...ids].join(' '));
    }
    return Object.keys(errors).length===0;
  }
  document.addEventListener('input',event=>{if(event.target.form)validate(event.target.form,false,event.target);},true);
  document.addEventListener('invalid',event=>{if(event.target.form)validate(event.target.form,true);},true);
  document.addEventListener('submit',event=>{
    const form=event.target;
    if(event.submitter?.getAttribute('formaction')?.split('?')[0].endsWith('/remove'))return;
    if(!validate(form,true)){event.preventDefault();event.stopImmediatePropagation();form.reportValidity();}
  },true);
}
