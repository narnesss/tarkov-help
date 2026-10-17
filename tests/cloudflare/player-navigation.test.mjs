import assert from 'node:assert/strict';
import {test} from 'node:test';
import {navigationAssetRequest,playerNavigation} from '../../cloudflare/player-navigation.mjs';

const origin='https://tarkov-compass.narnesss.workers.dev';
const config={origin:new URL('https://narnes.alwaysdata.net'),token:'t'.repeat(64)};
const page=()=>new Response('<header>export</header>',{headers:{'Content-Type':'text/html; charset=utf-8',ETag:'old','Last-Modified':'yesterday','Vary':'Accept-Encoding'}});
const request=(path='/')=>new Request(origin+path,{headers:{Cookie:'other=private; eft_session=opaque; admin_cookie=private','CF-Connecting-IP':'198.51.100.1','If-None-Match':'old'}});

test('без сессии публичный экспорт остаётся общим; ассеты не персонализируются',async()=>{
  const req=new Request(origin),response=page();assert.equal(navigationAssetRequest(req),req);
  assert.equal(await playerNavigation(req,response,config),response);
  const asset=new Response('script',{headers:{'Content-Type':'text/javascript'}});
  assert.equal(await playerNavigation(request('/bootstrap.mjs'),asset,config),asset);
  const personal=navigationAssetRequest(request());assert.equal(personal.headers.has('If-None-Match'),false);assert.match(personal.headers.get('Cookie'),/eft_session=opaque/);
});

test('Worker вставляет PHP-фрагмент, удаляет статические гостевые узлы и запрещает общий кэш',async()=>{
  const originalFetch=globalThis.fetch,originalRewriter=globalThis.HTMLRewriter,calls=[];
  const html='<a id="profile-button" href="/account">Личный кабинет</a>';
  globalThis.HTMLRewriter=class{
    on(selector,handler){calls.push({selector,handler});return this;}
    transform(response){return response;}
  };
  globalThis.fetch=async(target,options)=>{
    assert.equal(target.href,'https://narnes.alwaysdata.net/api/player-navigation?page=index');
    assert.deepEqual(options.headers,{'X-Wiki-Proxy-Token':config.token,'X-Wiki-Client-IP':'198.51.100.1',Cookie:'eft_session=opaque'});
    assert.equal(options.redirect,'manual');assert.equal(options.cache,'no-store');return Response.json({html});
  };
  try{
    const response=await playerNavigation(request(),page(),config);
    assert.equal(response.headers.get('Cache-Control'),'private, no-store');assert.match(response.headers.get('Vary'),/Cookie/);
    assert.equal(response.headers.has('ETag'),false);assert.equal(response.headers.has('Last-Modified'),false);
    assert.deepEqual(calls.map(c=>c.selector),['#profile-button','[data-player-guest]']);
    calls[0].handler.element({replace(value,options){assert.equal(value,html);assert.deepEqual(options,{html:true});}});
    let removed=false;calls[1].handler.element({remove(){removed=true;}});assert.ok(removed);
  }finally{globalThis.fetch=originalFetch;globalThis.HTMLRewriter=originalRewriter;}
});

test('при сбое или старом API гостевые кнопки не возвращаются; HEAD не вызывает PHP',async()=>{
  const originalFetch=globalThis.fetch,originalRewriter=globalThis.HTMLRewriter;let replacement='';
  globalThis.HTMLRewriter=class{
    on(selector,handler){if(selector==='#profile-button')handler.element({replace(value){replacement=value;}});return this;}
    transform(response){return response;}
  };
  try{
    for(const result of [new Response('redirect',{status:302}),Response.json({error:'old route'},{status:404})]){
      globalThis.fetch=async()=>result;await playerNavigation(request(),page(),config);
      assert.match(replacement,/Личный кабинет/);assert.doesNotMatch(replacement,/view=login|view=register|data-player-guest/);
    }
    globalThis.fetch=async()=>{throw Error('offline');};await playerNavigation(request(),page(),config);
    assert.doesNotMatch(replacement,/view=login|view=register/);
    let fetched=false;globalThis.fetch=async()=>{fetched=true;};
    const head=await playerNavigation(new Request(request(),{method:'HEAD'}),page(),config);assert.equal(fetched,false);assert.equal(await head.text(),'');
  }finally{globalThis.fetch=originalFetch;globalThis.HTMLRewriter=originalRewriter;}
});
