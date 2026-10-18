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

test('начальная загрузка получает только прогресс подтверждённого владельца одним запросом',async()=>{
 const calls=[],store=new ProfileStore({storage:storage(),request:async(path,options)=>{
  calls.push(path);assert.equal(options.headers['X-Wiki-Include-State'],'1');
  return {ok:true,json:async()=>({csrf:'same',profile:{id:'owner',name:'Игрок',authenticated:true},state:{'kord-achievements-pve':{value:['earned'],version:7}}})};
 }});
 await store.initialize('php');assert.deepEqual(calls,['/api/session']);assert.equal(store.profile.id,'owner');assert.equal(store.csrf,'same');assert.deepEqual(store.read('kord-achievements-pve'),['earned']);assert.equal(store.record('kord-achievements-pve').version,7);
});

test('новый клиент сохраняет совместимость с прежним API сессии',async()=>{
 const calls=[],store=new ProfileStore({storage:storage(),request:async path=>{
  calls.push(path);return {ok:true,json:async()=>path==='/api/session'?{csrf:'same',profile:{id:'owner',authenticated:true}}:{state:{'kord-achievements-pve':{value:['old-api'],version:3}}}};
 }});
 await store.initialize('php');assert.deepEqual(calls,['/api/session','/api/state']);assert.deepEqual(store.read('kord-achievements-pve'),['old-api']);
});

test('пустой прогресс старого JSON-адаптера не вызывает лишний запрос',async()=>{
 for(const state of [{},[]]){
  const calls=[],store=new ProfileStore({storage:storage(),request:async path=>{
   calls.push(path);return {ok:true,json:async()=>({csrf:'same',profile:{id:'empty-owner',authenticated:false},state})};
  }});
  await store.initialize('php');assert.deepEqual(calls,['/api/session']);assert.deepEqual(store.keys(),[]);
 }
});
