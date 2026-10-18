import test from 'node:test';
import assert from 'node:assert/strict';
import {publicCachedResponse} from '../../public/public-content-cache.mjs';
const old='a'.repeat(64),fresh='b'.repeat(64),url='/api/content/data/guide/quests-pve.json?view=catalog';
test('условное чтение использует публичный JSON без второго запроса',async()=>{
 const calls=[],cache={available:true,get:async()=>({revision:old,text:'{"tasks":[]}'}),put:()=>{throw Error('Лишняя запись');}};
 const reply=await publicCachedResponse(url,{cache,request:async(path,options)=>{calls.push(path);assert.equal(options.credentials,'omit');assert.equal(options.cache,'no-store');assert.equal(options.headers['X-Wiki-Content-Revision'],old);return Response.json({revision:old},{headers:{'X-Wiki-Content-Revision':old,'X-Wiki-Content-Unchanged':'1'}});}});
 assert.deepEqual(calls,[url]);assert.deepEqual(await reply.json(),{tasks:[]});assert.equal(reply.headers.get('X-Wiki-Browser-Cache'),'HIT');
});

test('новая версия и старый API возвращают свежий JSON за один запрос',async()=>{
 for(const header of [fresh,undefined]){
  const cache={available:true,get:async()=>({revision:old,text:'{"old":true}'}),put:async()=>{}};
  let calls=0;const reply=await publicCachedResponse(url,{cache,request:async()=>{calls++;return Response.json({new:true},{headers:header?{'X-Wiki-Content-Revision':header}:{}});}});
  assert.equal(calls,1);assert.deepEqual(await reply.json(),{new:true});
  await assert.rejects(publicCachedResponse(url,{cache,request:async()=>{throw Error('offline');}}),/offline/);
 }
});

test('снятие с публикации и отказ не подменяются сохранённой копией',async()=>{
 const cache={available:true,get:async()=>({revision:old,text:'{"old":true}'}),put:()=>{throw Error('Ошибка не кэшируется');}};
 for(const status of [404,503]){const reply=await publicCachedResponse(url,{cache,request:async()=>Response.json({error:true},{status})});assert.equal(reply.status,status);assert.deepEqual(await reply.json(),{error:true});}
});

test('профиль и прогресс не читаются и не сохраняются в общем кэше',async()=>{
 for(const path of ['/api/session','/api/state','/api/auth/options','/api/content/data/wiki/quests.json']){
  const cache={available:true,get:()=>{throw Error('Чтение личных данных из кэша');},put:()=>{throw Error('Запись личных данных');}};
  const reply=await publicCachedResponse(path,{cache,request:async()=>Response.json({private:true})});assert.deepEqual(await reply.json(),{private:true});
 }
});
test('недоступный IndexedDB и старая версия сервера не мешают загрузке',async()=>{
 const cache={available:true,get:async()=>{throw Error('storage denied');},put:()=>{throw Error('Не должен сохраняться ответ без версии');}};
 const reply=await publicCachedResponse(url,{cache,request:async()=>Response.json({tasks:[]})});assert.deepEqual(await reply.json(),{tasks:[]});
});
