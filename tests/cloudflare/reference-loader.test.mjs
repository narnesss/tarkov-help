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
  assert.equal(calls.length,3);assert.equal(result[2].tasks[0].id,'stable');assert.equal(result[5],null);
  assert.ok(calls.includes('data/guide/reference-pve.json'));assert.ok(calls.includes('data/guide/items-pve.json'));
});
test('боссы и БТР загружаются только при открытии их раздела',async()=>{
  for(const tab of ['items','crafts','bosses','btr']){
    const paths=[];await loadReferenceSources('pve',{dataset:async()=>({cms:true}),json:async path=>{paths.push(path);return {};},extras:async()=>({})},tab);
    assert.deepEqual(paths,tab==='bosses'?['data/guide/bosses.json']:tab==='btr'?['data/guide/btr.json']:[]);
  }
});
test('связи квестов нужны для рецептов, бартеров и нужных предметов',async()=>{
  for(const tab of ['items','needed','crafts','barters']){
    let selected;await loadReferenceSources('pve',{dataset:async(key,path,options)=>{if(key==='quests-pve')selected=options.view;return {cms:true};},json:async()=>({}),extras:async()=>({})},tab);
    assert.equal(selected,tab==='items'?'lookup':'catalog');
  }
});
test('карточка предмета получает только выбранную запись и её серверные связи',async()=>{
 const calls=[],selected={items:[{id:'selected'}],related:{reference:{crafts:[{id:'craft'}]},quests:{cms:true,tasks:[{id:'related'}]}}};
 const result=await loadReferenceSources('regular',{dataset:async(key,path,options)=>{calls.push([path,options]);return selected;},json:()=>{throw Error('Лишний запрос');}},'items','selected');
 assert.deepEqual(calls,[['data/guide/items-regular.json',{view:'detail',id:'selected'}]]);assert.equal(result[2].tasks[0].id,'related');assert.equal(result[0].crafts[0].id,'craft');
});

test('достижения не загружают предметы, квесты или данные других разделов',async()=>{
 const calls=[];await loadReferenceSources('pve',{dataset:async(key,path,options)=>{calls.push([path,options]);return {};},json:()=>{throw Error('Лишний запрос');}},'achievements');
 assert.deepEqual(calls,[['data/guide/reference-pve.json',{view:'catalog',section:'achievements'}]]);
});

test('ошибка основного каталога не заменяется неполным справочником',async()=>{
  await assert.rejects(loadReferenceSources('pve',{
    dataset:async()=>{throw Error('Нет каталога');},json:async()=>({}),extras:async()=>({}),
  }),/Нет каталога/);
});
