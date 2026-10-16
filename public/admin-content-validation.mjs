// Клиентская помощь редактору; окончательное решение всегда принимает сервер.
export function safeContentUrl(value){
  if(/[\s\p{C}<>"'\\]/u.test(value)||value.startsWith('//'))return false;
  if(/^[a-z][a-z0-9+.-]*:/i.test(value)){
    try{const url=new URL(value);return /^https?:$/i.test(url.protocol)&&/^https?:\/\/[^/]/i.test(value)&&!!url.hostname&&!url.username&&!url.password;}catch{return false;}
  }
  return !value.split('/')[0].includes(':');
}
export function contentError(value,{required=false,max=100000,url=false,search=false}={}){
  const text=value.trim();
  if(required&&!text)return 'Заполните это поле.';
  if([...text].length>max)return `Допустимо не более ${max} символов.`;
  if(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u.test(text)||(!search&&/<\s*[!\/?a-zA-Z][^>]*>/u.test(text)))return 'Введите обычный текст без HTML и управляющих символов.';
  if(url&&text&&!safeContentUrl(text))return 'Укажите HTTP(S)-ссылку или относительный путь без опасных символов.';
  return '';
}

if(typeof document!=='undefined'){
  const selector='[data-admin-text],[data-admin-search],input[type="number"]';
  let nextId=0;
  function validate(form,force=false,changed=null,trim=false){
    let valid=true;
    for(const input of form.querySelectorAll(selector)){
      if(input.disabled||input.readOnly)continue;
      input.setCustomValidity('');
      let error='';
      if(input.matches('[data-admin-text],[data-admin-search]')){
        if(trim)input.value=input.value.trim();
        error=contentError(input.value,{required:input.required,max:input.maxLength>=0?input.maxLength:100000,url:input.dataset.adminUrl==='1',search:input.hasAttribute('data-admin-search')});
      }
      if(error)input.setCustomValidity(error);
      const invalid=!input.validity.valid;valid&&=!invalid;
      if(!force&&input!==changed&&!input.hasAttribute('aria-invalid'))continue;
      let feedbackId=input.dataset.adminFeedback;
      if(!feedbackId){feedbackId='content-client-error-'+(++nextId);input.dataset.adminFeedback=feedbackId;}
      let feedback=document.getElementById(feedbackId);
      if(!feedback){feedback=document.createElement('div');feedback.id=feedbackId;feedback.className='invalid-feedback';feedback.setAttribute('role','alert');input.insertAdjacentElement('afterend',feedback);}
      feedback.textContent=invalid?(error||input.validationMessage):'';feedback.style.display=invalid?'block':'none';
      input.classList.toggle('is-invalid',invalid);
      if(invalid)input.setAttribute('aria-invalid','true');else input.removeAttribute('aria-invalid');
      const ids=new Set((input.getAttribute('aria-describedby')??'').split(' ').filter(Boolean));ids.add(feedbackId);input.setAttribute('aria-describedby',[...ids].join(' '));
    }
    return valid;
  }
  document.addEventListener('input',event=>{if(event.target.form)validate(event.target.form,false,event.target);},true);
  document.addEventListener('focusout',event=>{if(event.target.matches(selector)&&event.target.form){if(event.target.matches('[data-admin-text],[data-admin-search]'))event.target.value=event.target.value.trim();validate(event.target.form,false,event.target);}},true);
  document.addEventListener('invalid',event=>{if(event.target.form)validate(event.target.form,true);},true);
  document.addEventListener('submit',event=>{if(!validate(event.target,true,null,true)){event.preventDefault();event.stopImmediatePropagation();event.target.reportValidity();}},true);
}
