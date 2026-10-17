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
test('ошибочный набор не подменяется статическими файлами',async()=>{
 for(const response of [null,Response.json({files:{}})])await assert.rejects(loadContentBundle('quest-extras',{bundle:async()=>response,read:()=>{throw Error('Статические данные запрещены');}}),/неполный набор/);
});

test('загрузка набора сохраняет private API-контракт и не отправляет cookie',async()=>{
  const before=globalThis.document;globalThis.document={querySelector:()=>({content:'php'})};
  try{
    const response=await contentBundleResponse('quest-extras',undefined,async(path,options)=>{
      assert.equal(path,'/api/content/bundle/quest-extras');assert.equal(options.cache,'no-store');assert.equal(options.credentials,'omit');return Response.json({files:{}});
    });
    assert.ok(response);await assert.rejects(contentBundleResponse('raid-base',undefined,async()=>new Response('',{status:503})),/загрузить данные с сервера/);
    assert.equal(await contentBundleResponse('../state',undefined,()=>{throw Error('Недопустимый запрос');}),null);
  }finally{globalThis.document=before;}
});
