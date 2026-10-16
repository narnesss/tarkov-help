// Сообщение остаётся рядом с полем; исправление значения снимает его подсветку.
export function formFeedback(form,blocks){
  const fields=()=>Array.from(form.elements).filter(field=>field.name&&field.setAttribute);
  function clear(){
    for(const field of fields())field.removeAttribute('aria-invalid');
    for(const block of new Set(Object.values(blocks))){block.textContent='';block.hidden=true;}
  }
  function show(message,names){
    clear();
    const targets=names.map(name=>form.elements[name]).filter(Boolean),block=blocks[names[0]];
    if(!block)return false;
    block.textContent=message;block.hidden=false;
    for(const field of targets){field.setAttribute('aria-invalid','true');
      const descriptions=new Set((field.getAttribute('aria-describedby')??'').split(' ').filter(Boolean));
      descriptions.add(block.id);field.setAttribute('aria-describedby',[...descriptions].join(' '));
    }
    targets[0]?.focus();return true;
  }
  form.addEventListener('input',clear);
  form.addEventListener('invalid',event=>{if(blocks[event.target.name]){event.preventDefault();show(event.target.validationMessage,[event.target.name]);}},true);
  return {clear,show};
}

export function fieldError(message,...fields){return Object.assign(new Error(message),{fields});}
