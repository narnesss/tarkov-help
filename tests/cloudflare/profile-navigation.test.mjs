import test from 'node:test';
import assert from 'node:assert/strict';
import {renderProfileNavigation} from '../../public/profile-navigation.mjs';
function render(store){
 const attributes=new Map(),trigger={setAttribute:(key,value)=>attributes.set(key,value),removeAttribute:key=>attributes.delete(key)},links=[{hidden:true},{hidden:true}];
 renderProfileNavigation(store,trigger,links);return {trigger,links,attributes};
}
test('неподтверждённая сессия не показывает гостевые кнопки даже при устаревшем локальном профиле',()=>{
 for(const authenticated of [false,true]){
  const {trigger,links,attributes}=render({server:true,sessionConfirmed:false,profile:{authenticated,name:'Старый профиль'}});
  assert.equal(trigger.textContent,'Личный кабинет');assert.ok(links.every(link=>link.hidden));assert.equal(attributes.get('aria-busy'),'true');assert.equal(attributes.get('aria-label'),'Личный кабинет');
 }
});
test('подтверждённый аккаунт сохраняет только кнопку кабинета, гость получает вход и регистрацию',()=>{
 for(const authenticated of [false,true]){
  const {trigger,links,attributes}=render({server:true,sessionConfirmed:true,profile:{authenticated,name:'Игрок'}});
  assert.equal(trigger.textContent,authenticated?'Личный кабинет':'Мой прогресс');assert.ok(links.every(link=>link.hidden===authenticated));assert.ok(!attributes.has('aria-busy'));
 }
 const {trigger}=render({server:false,sessionConfirmed:false,profile:{name:'Локальный профиль'}});assert.equal(trigger.textContent,'Мой прогресс');
});
