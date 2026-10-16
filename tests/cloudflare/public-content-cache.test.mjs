import test from 'node:test';
import assert from 'node:assert/strict';
import {publicCachedResponse} from '../../public/public-content-cache.mjs';
const old='a'.repeat(64),fresh='b'.repeat(64),url='/api/content/data/guide/quests-pve.json?view=catalog';
test('повторная загрузка запрашивает только версию и повторно использует публичный JSON',async()=>{
 const calls=[],cache={available:true,get:async()=>({revision:old,text:'{"tasks":[]}'}),put:()=>{throw Error('Лишняя запись');}};
 const reply=await publicCachedResponse(url,{cache,request:async(path,options)=>{calls.push(path);assert.equal(options.credentials,'omit');assert.equal(options.cache,'no-store');return Response.json({revision:old});}});
 assert.deepEqual(calls,[url+'&revision=1']);assert.deepEqual(await reply.json(),{tasks:[]});assert.equal(reply.headers.get('X-Wiki-Browser-Cache'),'HIT');
});
test('изменённая версия требует свежую выдачу, а ошибка проверки не возвращает старые данные',async()=>{
 const cache={available:true,get:async()=>({revision:old,text:'{"old":true}'}),put:async()=>{}};
 let calls=0;const reply=await publicCachedResponse(url,{cache,request:async()=>++calls===1?Response.json({revision:fresh}):Response.json({new:true},{headers:{'X-Wiki-Content-Revision':fresh}})});
 assert.equal(calls,2);assert.deepEqual(await reply.json(),{new:true});
 await assert.rejects(publicCachedResponse(url,{cache,request:async()=>{throw Error('offline');}}),/offline/);
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
