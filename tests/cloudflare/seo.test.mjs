import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';
import worker from '../../cloudflare/worker.mjs';
import {seoResponse} from '../../cloudflare/seo.mjs';

const origin = 'https://wiki.test';
const config = {origin:new URL('https://origin.test'), token:'t'.repeat(64)};
const env = {API_ENABLED:'true', API_ORIGIN:config.origin.href, API_PROXY_TOKEN:config.token,
  ASSETS:{fetch:async () => new Response('stale asset', {headers:{'Content-Type':'text/html'}})}};
const request = (path, options) => new Request(origin + path, options);
const html = '<!doctype html><html lang="ru"><title>Проба пера</title><h1>Проба пера</h1><p>Цели и прохождение.</p></html>';
const documentResponse = (status = 200) => Response.json({seoVersion:1, html, status}, {status});

test('SEO отдаёт актуальный HTML для каталога, карточек, торговца и legacy URL', async t => {
  t.mock.method(globalThis, 'fetch', async (target, options) => {
    assert.equal(target.origin + target.pathname, 'https://origin.test/api/content/seo');
    assert.equal(options.method, 'GET');
    return documentResponse();
  });
  for (const path of ['/quests', '/quests/', '/quests.html', '/quests/debut', '/quests/debut.html', '/quests/trader/prapor', '/quest?quest=debut', '/quest.html?quest=debut', '/reference/items/new-id?mode=regular', '/boss/killa', '/raid/shoreline', '/documents/woods', '/reference/ammo?page=2']) {
    const response = await worker.fetch(request(path), env);
    assert.equal(response.status, 200, path);
    assert.equal(await response.text(), html);
    assert.match(response.headers.get('Content-Type'), /^text\/html/);
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
    assert.equal(response.headers.get('Set-Cookie'), null);
  }
});

test('публичный SEO не передаёт cookie/Authorization/поддельные proxy-заголовки и лишние параметры', async t => {
  t.mock.method(globalThis, 'fetch', async (target, options) => {
    assert.deepEqual(Object.fromEntries(target.searchParams), {path:'/quests', mode:'regular', page:'2', quest:'debut', trader:'prapor', section:'all'});
    assert.deepEqual(options.headers, {'X-Wiki-Proxy-Token':config.token, 'X-Wiki-Client-IP':'198.51.100.1', Accept:'application/json'});
    assert.equal(options.credentials, 'omit');
    assert.equal(options.cache, 'no-store');
    assert.equal(options.redirect, 'manual');
    assert.equal(options.cf, undefined);
    assert.ok(options.signal instanceof AbortSignal);
    return documentResponse();
  });
  const response = await seoResponse(request('/quests?mode=regular&page=2&quest=debut&trader=prapor&section=all&path=https://evil.test&token=secret&mode=pve', {
    headers:{Cookie:'eft_session=private', Authorization:'Bearer private', 'CF-Connecting-IP':'198.51.100.1', 'X-Wiki-Client-IP':'forged', 'X-Wiki-Proxy-Token':'forged'},
  }), env, config);
  assert.equal(response.status, 200);
});

test('HTML-алиасы и завершающий слеш нормализуются до пути backend', async t => {
  const paths = [];
  t.mock.method(globalThis, 'fetch', async target => {
    paths.push(target.searchParams.get('path'));
    return documentResponse();
  });
  for (const path of ['/quests/debut.html', '/quests/debut.html/', '/quests/trader/prapor.html', '/quests/', '/quest.html?quest=debut']) {
    assert.equal((await worker.fetch(request(path), env)).status, 200);
  }
  assert.deepEqual(paths, ['/quests/debut', '/quests/debut', '/quests/trader/prapor', '/quests', '/quest']);
});

test('HEAD проверяет актуальный backend и возвращает заголовки без HTML', async t => {
  t.mock.method(globalThis, 'fetch', async (_target, options) => {
    assert.equal(options.method, 'GET');
    return documentResponse();
  });
  const response = await worker.fetch(request('/quests/debut', {method:'HEAD'}), env);
  assert.equal(response.status, 200);
  assert.equal(await response.text(), '');
  assert.equal(response.headers.get('Cache-Control'), 'no-store');
});

test('SEO HTML сохраняет приватную PHP-навигацию отдельным запросом с сессией', async t => {
  const originalRewriter = globalThis.HTMLRewriter, calls = [];
  globalThis.HTMLRewriter = class { on() { return this; } transform(response) { return response; } };
  t.after(() => { globalThis.HTMLRewriter = originalRewriter; });
  t.mock.method(globalThis, 'fetch', async (target, options) => {
    calls.push(target.pathname);
    if (target.pathname === '/api/content/seo') {
      assert.equal(options.headers.Cookie, undefined);
      return documentResponse();
    }
    assert.equal(target.pathname, '/api/player-navigation');
    assert.equal(options.headers.Cookie, 'eft_session=opaque');
    return Response.json({html:'<a id="profile-button" href="/account">Личный кабинет</a>'});
  });
  const response = await worker.fetch(request('/quests/debut', {headers:{Cookie:'other=private; eft_session=opaque'}}), env);
  assert.deepEqual(calls, ['/api/content/seo', '/api/player-navigation']);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('Cache-Control'), 'private, no-store');
  assert.match(response.headers.get('Vary'), /Cookie/);
});

test('снятый с публикации квест возвращает 404 даже при существующем dist', async t => {
  t.mock.method(globalThis, 'fetch', async () => documentResponse(404));
  let assets = 0;
  const response = await worker.fetch(request('/quests/debut'), {...env, ASSETS:{fetch:async () => { assets++; return new Response('stale'); }}});
  assert.equal(response.status, 404);
  assert.equal(response.headers.get('X-Robots-Tag'), 'noindex');
  assert.equal(await response.text(), html);
  assert.equal(assets, 0);
});

test('архивные предметы, боссы и карты получают 404 даже при существующем dist', async t => {
  t.mock.method(globalThis, 'fetch', async () => documentResponse(404));
  for (const path of ['/reference/items/old-id', '/boss/killa', '/raid/shoreline', '/documents/woods']) {
    const response = await worker.fetch(request(path), {...env, ASSETS:{fetch:async () => { throw Error('Устаревший asset недопустим'); }}});
    assert.equal(response.status, 404, path);
    assert.equal(response.headers.get('X-Robots-Tag'), 'noindex');
  }
});

test('старый API без SEO endpoint сохраняет прежний статический frontend', async t => {
  t.mock.method(globalThis, 'fetch', async () => Response.json({error:'Not found'}, {status:404}));
  const response = await worker.fetch(request('/quests/debut'), env);
  assert.equal(response.status, 200);
  assert.equal(await response.text(), 'stale asset');
});

test('5xx, redirect, невалидный JSON и чужая схема не заменяются старым dist', async t => {
  for (const makeResponse of [
    () => new Response('offline', {status:503}),
    () => new Response('', {status:302, headers:{Location:'https://other.test'}}),
    () => new Response('<html>missing</html>', {status:404, headers:{'Content-Type':'text/html'}}),
    () => new Response('{broken', {headers:{'Content-Type':'application/json'}}),
    () => Response.json({html, status:200}),
    () => Response.json({seoVersion:1, html, status:302}),
    () => Response.json({seoVersion:1, html, status:200}, {status:404}),
    () => Response.json({seoVersion:1, html:'', status:200}),
  ]) {
    const mock = t.mock.method(globalThis, 'fetch', async () => makeResponse());
    const response = await worker.fetch(request('/quests/debut'), env);
    assert.equal(response.status, 503);
    assert.equal(response.headers.get('Retry-After'), '60');
    assert.equal(response.headers.get('X-Robots-Tag'), 'noindex');
    assert.doesNotMatch(await response.text(), /stale asset|broken/);
    mock.mock.restore();
  }
});

test('сетевая ошибка и лимит 7 секунд дают 503 без fallback', async t => {
  const controller = new AbortController();
  t.mock.method(AbortSignal, 'timeout', milliseconds => { assert.equal(milliseconds, 7000); return controller.signal; });
  t.mock.method(globalThis, 'fetch', async (_target, options) => new Promise((_, reject) => {
    options.signal.addEventListener('abort', () => reject(new Error('private origin failure')), {once:true});
    controller.abort();
  }));
  const response = await worker.fetch(request('/quests/debut'), env);
  assert.equal(response.status, 503);
  assert.doesNotMatch(await response.text(), /private origin failure|stale asset/);
});

test('срок ожидания ограничивает также зависший поток тела', async t => {
  const controller = new AbortController();
  t.mock.method(AbortSignal, 'timeout', () => controller.signal);
  let cancelled = false;
  t.mock.method(globalThis, 'fetch', async () => new Response(new ReadableStream({
    pull() { controller.abort(); }, cancel() { cancelled = true; },
  }), {headers:{'Content-Type':'application/json'}}));
  const response = await worker.fetch(request('/quests/debut'), env);
  assert.equal(response.status, 503);
  assert.equal(cancelled, true);
});

test('лимит 2 МиБ проверяется по заголовку и реальным байтам потока', async t => {
  for (const makeResponse of [
    () => new Response('{}', {headers:{'Content-Type':'application/json', 'Content-Length':String(2 * 1024 * 1024 + 1)}}),
    () => Response.json({seoVersion:1, html:'я'.repeat(1024 * 1024), status:200}),
  ]) {
    const mock = t.mock.method(globalThis, 'fetch', async () => makeResponse());
    assert.equal((await worker.fetch(request('/quests/debut'), env)).status, 503);
    mock.mock.restore();
  }
});

test('sitemap возвращает XML из backend без персональной навигации', async t => {
  const xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
  let calls = 0;
  t.mock.method(globalThis, 'fetch', async target => {
    calls++;
    assert.equal(target.pathname, '/api/content/seo');
    return Response.json({seoVersion:1, xml, status:200});
  });
  for (const path of ['/sitemap.xml', '/sitemaps/quests.xml', '/sitemaps/pages.xml', '/sitemaps/items-pve.xml', '/sitemaps/items-regular.xml']) {
    const response = await worker.fetch(request(path, {headers:{Cookie:'eft_session=private'}}), env);
    assert.equal(response.status, 200);
    assert.equal(response.headers.get('Content-Type'), 'application/xml; charset=utf-8');
    assert.equal(await response.text(), xml);
  }
  assert.equal(calls, 5);
  assert.equal(await (await worker.fetch(request('/sitemap.xml', {method:'HEAD'}), env)).text(), '');
});

test('robots.txt читается из корневого ASSETS без обращения к origin', async t => {
  t.mock.method(globalThis, 'fetch', async () => { throw new Error('Origin не нужен'); });
  const response = await worker.fetch(request('/robots.txt?token=ignored'), {...env, ASSETS:{fetch:async incoming => {
    assert.equal(incoming.url, origin + '/robots.txt');
    assert.equal(incoming.headers.get('Cookie'), null);
    return new Response('User-agent: *\nAllow: /\n');
  }}});
  assert.equal(response.status, 200);
  assert.match(response.headers.get('Content-Type'), /^text\/plain/);
  assert.match(await response.text(), /Allow: \//);
});

test('неподдерживаемые методы и небезопасные пути не достигают backend', async t => {
  let calls = 0;
  t.mock.method(globalThis, 'fetch', async () => { calls++; return documentResponse(); });
  for (const path of ['/quests', '/quest.html', '/sitemap.xml', '/robots.txt']) {
    const response = await worker.fetch(request(path, {method:'POST'}), env);
    assert.equal(response.status, 405);
    assert.equal(response.headers.get('Allow'), 'GET, HEAD');
  }
  for (const path of ['/quests/%2Fadmin', '/quests/debut/extra', '/quests/' + 'a'.repeat(513), '/sitemaps/private.xml']) {
    assert.equal((await worker.fetch(request(path), env)).status, 404);
  }
  assert.equal(calls, 0);
});

test('ошибка валидации query сохраняет 422 и закрывается от индексации', async t => {
  t.mock.method(globalThis, 'fetch', async () => Response.json({errors:{page:['Некорректная страница']}}, {status:422}));
  const response = await worker.fetch(request('/quests?page=invalid'), env);
  assert.equal(response.status, 422);
  assert.equal(response.headers.get('X-Robots-Tag'), 'noindex');
});

test('API выключен: прежние assets доступны без origin; Wrangler включает SEO-маршруты', async t => {
  t.mock.method(globalThis, 'fetch', async () => { throw new Error('API выключен'); });
  assert.equal(await (await worker.fetch(request('/quests'), {...env, API_ENABLED:'false'})).text(), 'stale asset');
  const wrangler = JSON.parse(await readFile(new URL('../../wrangler.jsonc', import.meta.url), 'utf8'));
  for (const path of ['/robots.txt', '/sitemap.xml', '/sitemaps/*']) assert.ok(wrangler.assets.run_worker_first.includes(path));
  assert.equal(wrangler.keep_vars, true);
  assert.equal(wrangler.vars.API_ENABLED, undefined);
});

test('API включён с повреждёнными настройками: 503 вместо устаревшего экспорта', async t => {
  t.mock.method(globalThis, 'fetch', async () => { throw new Error('Некорректный origin не вызывается'); });
  for (const variant of [{API_PROXY_TOKEN:''}, {API_ORIGIN:'http://insecure.test'}]) {
    const response = await worker.fetch(request('/quests/debut'), {...env, ...variant});
    assert.equal(response.status, 503);
    assert.equal(response.headers.get('Retry-After'), '60');
    assert.doesNotMatch(await response.text(), /stale asset/);
  }
});
