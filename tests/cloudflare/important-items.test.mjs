import assert from 'node:assert/strict';
import {test} from 'node:test';
import worker from '../../cloudflare/worker.mjs';

const origin = 'https://wiki.test';
const env = {API_ENABLED:'true', API_ORIGIN:'https://origin.test', API_PROXY_TOKEN:'t'.repeat(64), ASSETS:{fetch:async request=>new Response(new URL(request.url).pathname)}};
test('пагинация получает готовый HTML, не пересылая cookies и приватные заголовки', async () => {
  const original = globalThis.fetch;
  globalThis.fetch = async (url, options) => {
    assert.equal(url.href,'https://origin.test/api/content/important-items?mode=pve&page=2&q=%D0%91%D0%BE%D0%BB%D1%82');
    assert.equal(options.headers.Cookie,undefined);
    assert.equal(options.headers.Authorization,undefined);
    assert.equal(options.headers['X-Wiki-Proxy-Token'],env.API_PROXY_TOKEN);
    assert.equal(options.headers['X-Wiki-Client-IP'],'198.51.100.2');
    assert.equal(options.redirect,'manual'); assert.equal(options.cache,'no-store');
    return Response.json({html:'<!doctype html><main>Страница 2</main>'});
  };
  try {
    const request = new Request(origin+'/important-items?mode=pve&page=2&q=%D0%91%D0%BE%D0%BB%D1%82', {headers:{Cookie:'private=secret', Authorization:'Bearer secret','CF-Connecting-IP':'198.51.100.2'}});
    const response = await worker.fetch(request,env);
    assert.equal(response.status,200); assert.match(await response.text(),/Страница 2/);
    assert.match(response.headers.get('Content-Type'),/text\/html/);
    assert.equal(response.headers.has('Set-Cookie'),false);
  } finally { globalThis.fetch = original; }
});

test('без API и при ошибках старый список не подставляется', async () => {
  assert.equal((await worker.fetch(new Request(origin+'/important-items?mode=regular&page=2'),{...env,API_ENABLED:'false'})).status,503);
  assert.equal((await worker.fetch(new Request(origin+'/important-items?page=0'),{...env,API_ENABLED:'false'})).status,503);
  assert.equal((await worker.fetch(new Request(origin+'/important-items',{method:'POST'}),env)).status,405);
  const original = globalThis.fetch;
  try {
    for (const status of [404,422,503]) {
      globalThis.fetch=async()=>Response.json({error:'upstream'}, {status});
      const response=await worker.fetch(new Request(origin+'/important-items'),env);
      assert.equal(response.status,status);
      assert.match(response.headers.get('Content-Type'),/text\/html/);
    }
    globalThis.fetch=async()=>new Response('',{status:302,headers:{Location:'https://evil.test'}});
    assert.equal((await worker.fetch(new Request(origin+'/important-items'),env)).status,503);
    globalThis.fetch=async()=>Response.json({html:'body'});
    assert.equal(await (await worker.fetch(new Request(origin+'/important-items',{method:'HEAD'}),env)).text(),'');
  } finally { globalThis.fetch=original; }
});
