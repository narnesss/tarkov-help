import test from 'node:test';
import assert from 'node:assert/strict';
import {ProfileStore} from '../../public/profile-store.mjs';
import {applyPlayerProfile} from '../../public/profile-identity.mjs';

const storage=()=>{const values=new Map();return {getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value),get length(){return values.size},key:index=>[...values.keys()][index]};};
test('подтверждённый аккаунт сохраняется при ошибке загрузки прогресса',async()=>{
  const store=new ProfileStore({storage:storage(),request:async path=>{
    if(path==='/api/session')return {ok:true,json:async()=>({csrf:'new-csrf',profile:{id:'player',name:'Имя',authenticated:true,avatar:null}})};
    return {ok:false,status:503,json:async()=>({message:'temporary'})};
  }});
  store.meta.serverProfile={id:'guest',name:'Гость',authenticated:false};
  await assert.rejects(store.initialize('php'),/temporary/);
  assert.equal(store.sessionConfirmed,true);assert.equal(store.profile.authenticated,true);assert.equal(store.profile.id,'player');
  assert.equal(store.meta.serverProfile.id,'player');
});
test('обновление имени сохраняет аккаунт, CSRF и прогресс, отвергает подмену владельца',()=>{
  const store=new ProfileStore({storage:storage()});store.server=true;store.csrf='old';store.profile={id:'player',name:'Старое имя',authenticated:true};
  store.saveRecord('kord-achievements-pve',{value:['earned'],version:8,base:['earned'],dirty:false});
  applyPlayerProfile(store,{csrf:'same-session',profile:{id:'player',name:'Новое имя',authenticated:true,avatar:null}});
  assert.equal(store.profile.name,'Новое имя');assert.equal(store.csrf,'same-session');assert.deepEqual(store.read('kord-achievements-pve'),['earned']);
  assert.throws(()=>applyPlayerProfile(store,{profile:{id:'other',authenticated:true,name:'Другое'}}),/Сессия/);
  assert.throws(()=>applyPlayerProfile(store,{profile:{id:'player',authenticated:false,name:'Гость'}}),/Сессия/);
  assert.equal(store.profile.authenticated,true);assert.equal(store.profile.id,'player');
});
