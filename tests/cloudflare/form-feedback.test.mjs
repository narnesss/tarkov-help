import test from 'node:test';
import assert from 'node:assert/strict';
import {formFeedback} from '../../public/form-feedback.mjs';

test('ошибка выделяет поля, связывает сообщение и снимается после ввода',()=>{
  let focused=null;const events={};
  const field=name=>({name,attrs:{'aria-describedby':'password-help'},setAttribute(key,value){this.attrs[key]=value;},getAttribute(key){return this.attrs[key];},removeAttribute(key){delete this.attrs[key];},focus(){focused=name;}});
  const password=field('password'),confirmation=field('confirmation');
  const elements=[password,confirmation];Object.assign(elements,{password,confirmation});
  const block={id:'password-error',hidden:true,textContent:''};
  const feedback=formFeedback({elements,addEventListener(name,callback){events[name]=callback;}},{password:block,confirmation:block});
  feedback.show('Пароли не совпадают.',['password','confirmation']);
  assert.equal(block.hidden,false);assert.equal(block.textContent,'Пароли не совпадают.');assert.equal(focused,'password');
  for(const input of elements){assert.equal(input.attrs['aria-invalid'],'true');assert.equal(input.attrs['aria-describedby'],'password-help password-error');}
  events.input();assert.equal(block.hidden,true);assert.equal(password.attrs['aria-invalid'],undefined);
  password.validationMessage='Заполните пароль.';let prevented=false;
  events.invalid({target:password,preventDefault(){prevented=true;}});
  assert.equal(prevented,true);assert.equal(block.textContent,'Заполните пароль.');assert.equal(focused,'password');
});
