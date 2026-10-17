import assert from 'node:assert/strict';
import {test} from 'node:test';
import worker from '../../cloudflare/worker.mjs';

const origin='https://wiki.test';
const env={API_ENABLED:'true',API_ORIGIN:'https://origin.test',API_PROXY_TOKEN:'t'.repeat(64),
  ASSETS:{fetch:async request=>new URL(request.url).pathname==='/quest'?new Response('quest shell'):new Response('missing',{status:404})}};

test('новый опубликованный квест открывается без статического HTML и без кэширования',async()=>{
  const saved=globalThis.fetch;
  try{
    globalThis.fetch=async(url,options)=>{
      assert.equal(url.href,'https://origin.test/api/content/quest/new-quest');
      assert.equal(options.headers['X-Wiki-Proxy-Token'],env.API_PROXY_TOKEN);
      assert.equal(options.headers['X-Wiki-Client-IP'],'198.51.100.1');
      assert.equal(options.cache,'no-store');assert.equal(options.redirect,'manual');
      return Response.json({exists:true});
    };
    const reply=await worker.fetch(new Request(origin+'/quests/new-quest',{headers:{'CF-Connecting-IP':'198.51.100.1'}}),env);
    assert.equal(reply.status,200);assert.equal(await reply.text(),'quest shell');assert.equal(reply.headers.get('Cache-Control'),'private, no-store');
    globalThis.fetch=async()=>Response.json({exists:false});
    assert.equal((await worker.fetch(new Request(origin+'/quests/draft'),env)).status,404);
    assert.equal((await worker.fetch(new Request(origin+'/quests/new-quest'),{...env,API_ENABLED:'false'})).status,404);
  }finally{globalThis.fetch=saved;}
});

test('CMS читается без cookie; при ошибке API статический снимок запрещён',async()=>{
  const saved=globalThis.document;
  globalThis.document={querySelector:()=>({content:'php'})};
  try{
    const {contentResponse}=await import('../../public/content-client.mjs');
    const reply=await contentResponse('data/guide/quests-pve.json',undefined,async(url,options)=>{
      assert.equal(url,'/api/content/data/guide/quests-pve.json');
      assert.equal(options.credentials,'omit');assert.equal(options.cache,'no-store');
      return Response.json({cms:true,tasks:[]});
    });
    assert.equal((await reply.json()).cms,true);
    await assert.rejects(contentResponse('data/maps.json',undefined,async()=>new Response('',{status:503})),/загрузить данные с сервера/);
    assert.equal(await contentResponse('https://json.tarkov.dev/pve/tasks',undefined,()=>{throw Error('must not request');}),null);
  }finally{globalThis.document=saved;}
});
