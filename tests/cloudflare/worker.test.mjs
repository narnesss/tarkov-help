import assert from 'node:assert/strict';
import {test} from 'node:test';
import worker from '../../cloudflare/worker.mjs';

const origin = 'https://tarkov-compass.narnesss.workers.dev';
const env = {API_ENABLED: 'true', API_ORIGIN: 'https://narnes.alwaysdata.net', API_PROXY_TOKEN: 't'.repeat(64),
  ASSETS: {fetch: async request => new Response('static:' + new URL(request.url).pathname)}};

test('статические маршруты остаются assets; серверные профили включаются только с полными настройками', async () => {
  assert.equal(await (await worker.fetch(new Request(origin + '/raid/interchange'), env)).text(), 'static:/raid/interchange');
  for (const variant of [{}, {...env, API_ENABLED: undefined}, {...env, API_PROXY_TOKEN: ''}, {...env, API_ORIGIN: 'http://insecure.test'}]) {
    const runtime = await worker.fetch(new Request(origin + '/profile-runtime.mjs'), variant);
    assert.match(await runtime.text(), /serverProfiles = false/);
    assert.equal(runtime.headers.get('Cache-Control'), 'private, no-store');
    assert.equal((await worker.fetch(new Request(origin + '/api/session'), variant)).status, 503);
  }
  assert.match(await (await worker.fetch(new Request(origin + '/profile-runtime.mjs'), env)).text(), /serverProfiles = true/);
});

test('proxy сохраняет метод, JSON, cookie, origin, CSRF и Set-Cookie; заменяет поддельные заголовки и не кэширует', async () => {
  const original = globalThis.fetch;
  const body = JSON.stringify({value: {query: 'private'}, version: 0});
  globalThis.fetch = async (target, options) => {
    assert.equal(target.href, 'https://narnes.alwaysdata.net/api/state/wiki-ui-v1?mode=pve');
    assert.equal(options.method, 'PUT');
    assert.equal(await new Response(options.body).text(), body);
    assert.equal(options.redirect, 'manual');
    assert.equal(options.cf.cacheTtl, 0);
    for (const [name, value] of [['Origin', origin], ['X-CSRF-Token', 'csrf'], ['Cookie', 'eft_session=session'],
      ['X-Wiki-Proxy-Token', env.API_PROXY_TOKEN], ['X-Wiki-Client-IP', '198.51.100.1']]) assert.equal(options.headers.get(name), value);
    assert.equal(options.headers.has('X-Forwarded-For'), false);
    return Response.json({version: 1}, {headers: {'Set-Cookie': 'eft_session=new; Path=/; HttpOnly; Secure; SameSite=Lax', 'Cache-Control': 'public'}});
  };
  try {
    const response = await worker.fetch(new Request(origin + '/api/state/wiki-ui-v1?mode=pve', {method: 'PUT', body,
      headers: {'Content-Type': 'application/json', Origin: origin, 'X-CSRF-Token': 'csrf', Cookie: 'eft_session=session',
        'CF-Connecting-IP': '198.51.100.1', 'X-Wiki-Proxy-Token': 'forged', 'X-Wiki-Client-IP': 'forged', 'X-Forwarded-For': 'forged'}}), env);
    assert.deepEqual(await response.json(), {version: 1});
    assert.equal(response.headers.get('Cache-Control'), 'private, no-store');
    assert.match(response.headers.get('Set-Cookie'), /HttpOnly; Secure; SameSite=Lax/);
  } finally { globalThis.fetch = original; }
});

test('ошибки API сохраняются; редиректы, HTML и сетевые сбои дают приватный JSON 503', async () => {
  const original = globalThis.fetch;
  try {
    globalThis.fetch = async () => Response.json({error: 'conflict'}, {status: 409});
    assert.equal((await worker.fetch(new Request(origin + '/api/state'), env)).status, 409);
    for (const reply of [new Response('', {status: 302, headers: {Location: 'https://evil.test'}}), new Response('<html>error</html>')]) {
      globalThis.fetch = async () => reply;
      const response = await worker.fetch(new Request(origin + '/api/session'), env);
      assert.equal(response.status, 503); assert.ok((await response.json()).error);
    }
    globalThis.fetch = async () => { throw Error('offline'); };
    assert.equal((await worker.fetch(new Request(origin + '/api/session'), env)).status, 503);
  } finally { globalThis.fetch = original; }
});
