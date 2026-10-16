import test from 'node:test';
import assert from 'node:assert/strict';
import {loadReferenceSources} from '../../public/reference-loader.mjs';

test('CMS-справочник не запрашивает вспомогательные файлы квестов',async()=>{
  const calls=[];
  const result=await loadReferenceSources('pve',{
    dataset:async(key,url)=>{calls.push(url);return key==='quests-pve'?{cms:true,tasks:[{id:'stable'}]}:{key};},
    json:async url=>{calls.push(url);return {url};},
    extras:()=>{throw Error('Лишняя загрузка вспомогательных файлов');},
  });
  assert.equal(calls.length,5);assert.equal(result[2].tasks[0].id,'stable');assert.equal(result[5],null);
  assert.ok(calls.includes('data/guide/reference-pve.json'));assert.ok(calls.includes('data/guide/items-pve.json'));
});
test('автономный справочник сохраняет обогащение квестов и выбранный режим',async()=>{
  let extraCalls=0;const paths=[];const extra={media:{quests:{}}};
  const result=await loadReferenceSources('regular',{
    dataset:async(key,url)=>{paths.push(url);return {tasks:[]};},json:async()=>({}),
    extras:async()=>{extraCalls++;return extra;},
  });
  assert.equal(extraCalls,1);assert.equal(result[5],extra);
  assert.deepEqual(paths.sort(),['data/guide/items-regular.json','data/guide/quests-regular.json','data/guide/reference-regular.json']);
});
test('ошибка основного каталога не заменяется неполным справочником',async()=>{
  await assert.rejects(loadReferenceSources('pve',{
    dataset:async()=>{throw Error('Нет каталога');},json:async()=>({}),extras:async()=>({}),
  }),/Нет каталога/);
});
