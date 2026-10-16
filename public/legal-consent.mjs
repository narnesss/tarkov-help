// Метаданные получены от сервера; отсутствие галочек нельзя заменить текстом под кнопкой.
let metadata=null;
export function consentError(values){
  if(values.terms!==true)return 'Примите условия использования.';
  if(values.privacy!==true)return 'Подтвердите ознакомление с политикой конфиденциальности.';
  if(values.personal_data!==true)return 'Подтвердите отдельное согласие на обработку персональных данных.';
  return '';
}
export function configureLegal(value){
  if(typeof value?.required!=='boolean'||typeof value.version!=='string'||!/^[a-f0-9]{64}$/.test(value.version))throw new Error('Не удалось загрузить редакцию правовых документов. Обновите страницу.');
  if(metadata?.version!==value.version&&typeof document!=='undefined')for(const field of document.querySelectorAll('.legal-checkboxes input'))field.checked=false;
  metadata=value;
}
export function showLegal(fieldset,visible){if(!fieldset)return;fieldset.hidden=!visible||!metadata?.required||metadata?.accepted===true;fieldset.disabled=fieldset.hidden;}
export function collectLegal(fieldset){
  if(!metadata)throw Object.assign(new Error('Дождитесь загрузки правовых документов или обновите страницу.'),{legalConsent:true});
  if(!metadata.required||metadata.accepted===true)return {};
  const fields=Object.fromEntries(['terms','privacy','personal_data'].map(key=>[key,fieldset?.querySelector(`[name="legal_${key}"]`)]));
  const values=Object.fromEntries(Object.entries(fields).map(([key,field])=>[key,field?.checked===true]));
  const error=consentError(values),feedback=fieldset?.querySelector('[data-legal-error]');
  if(feedback){feedback.textContent=error;feedback.hidden=!error;}
  for(const [key,field] of Object.entries(fields))if(field){field.classList.toggle('is-invalid',!values[key]);if(!values[key])field.setAttribute('aria-invalid','true');else field.removeAttribute('aria-invalid');}
  if(error){Object.entries(fields).find(([key])=>!values[key])?.[1]?.focus();throw Object.assign(new Error(error),{legalConsent:true});}
  return {legal:{version:metadata.version,...values}};
}
export function showLegalFailure(fieldset,error){
  const errors=error.data?.errors??{},names=Object.keys(errors);
  if(!error.legalConsent&&!names.some(name=>name==='legal'||name.startsWith('legal.')))return false;
  const feedback=fieldset?.querySelector('[data-legal-error]');if(!feedback)return false;
  if(!metadata){fieldset.hidden=false;fieldset.disabled=true;}
  feedback.textContent=Object.values(errors).flat().find(value=>typeof value==='string')||error.message;feedback.hidden=false;return true;
}
if(typeof document!=='undefined')document.addEventListener('change',event=>{const fieldset=event.target.closest?.('.legal-checkboxes');if(!fieldset)return;const feedback=fieldset.querySelector('[data-legal-error]');feedback.hidden=true;event.target.classList.remove('is-invalid');event.target.removeAttribute('aria-invalid');});
if(typeof document!=='undefined')document.addEventListener('invalid',event=>{const fieldset=event.target.closest?.('.legal-checkboxes');if(!fieldset||fieldset.disabled)return;const feedback=fieldset.querySelector('[data-legal-error]');feedback.textContent='Отметьте каждое подтверждение перед продолжением.';feedback.hidden=false;event.target.setAttribute('aria-invalid','true');},true);
