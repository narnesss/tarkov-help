import assert from 'node:assert/strict';
import {test} from 'node:test';
import {loadContentBundle,PUBLIC_BUNDLES} from '../../public/content-bundles.mjs';
import {contentBundleResponse} from '../../public/content-client.mjs';

test('14 файлов квестов или 6 справочников карты приходят одним запросом без повторных загрузок',async()=>{
  for(const name of ['quest-extras','raid-base']){
    let calls=0;const files=Object.fromEntries(PUBLIC_BUNDLES[name].map(([path])=>[path,{path}]));
    const values=await loadContentBundle(name,{bundle:async requested=>{assert.equal(requested,name);calls++;return Response.json({files});},read:()=>{throw Error('Повторная загрузка файла');}});
    assert.equal(calls,1);assert.deepEqual(values.map(x=>x.path),PUBLIC_BUNDLES[name].map(([path])=>path));
  }
});
test('статический экспорт и временно недоступный новый endpoint сохраняют прежние файлы',async()=>{
  for(const response of [null,Response.json({files:{}})]){
    const values=await loadContentBundle('quest-extras',{bundle:async()=>response,read:async path=>{if(path.includes('quest-conditions'))throw Error('optional missing');return {path};}});
    assert.equal(values.length,14);assert.deepEqual(values[2],{quests:{}});
  }
  await assert.rejects(loadContentBundle('raid-base',{bundle:async()=>null,read:async()=>{throw Error('required missing');}}),/required missing/);
});
test('загрузка набора сохраняет private API-контракт и не отправляет cookie',async()=>{
  const before=globalThis.document;globalThis.document={querySelector:()=>({content:'php'})};
  try{
    const response=await contentBundleResponse('quest-extras',undefined,async(path,options)=>{
      assert.equal(path,'/api/content/bundle/quest-extras');assert.equal(options.cache,'no-store');assert.equal(options.credentials,'omit');return Response.json({files:{}});
    });
    assert.ok(response);assert.equal(await contentBundleResponse('raid-base',undefined,async()=>new Response('',{status:503})),null);
    assert.equal(await contentBundleResponse('../state',undefined,()=>{throw Error('Недопустимый запрос');}),null);
  }finally{globalThis.document=before;}
});
