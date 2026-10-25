import {navigationAssetRequest,playerNavigation} from './player-navigation.mjs';
import {seoResponse} from './seo.mjs';

/** Статика остаётся на Cloudflare; приватный API исполняется только на alwaysdata. */
function settings(env) {
  let origin;
  try { origin = new URL(env.API_ORIGIN); } catch { return null; }
  if (env.API_ENABLED !== 'true' || typeof env.API_PROXY_TOKEN !== 'string' || env.API_PROXY_TOKEN.length < 32
    || origin.protocol !== 'https:' || origin.username || origin.password || origin.pathname !== '/' || origin.search || origin.hash) return null;
  return {origin, token: env.API_PROXY_TOKEN};
}

const privateHeaders = {'Cache-Control': 'private, no-store', 'X-Content-Type-Options': 'nosniff'};
const yandexVerification = '/yandex_78b9cda6dd2aaa25.html';
function unavailable() {
  return Response.json({error: 'Синхронизация временно недоступна. Данные в браузере сохранены.'}, {status: 503, headers: privateHeaders});
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url), config = settings(env);
    if (url.pathname === yandexVerification || url.pathname === yandexVerification.slice(0, -5)) {
      const headers = new Headers({'Content-Type':'text/html; charset=utf-8', 'Cache-Control':'no-store', 'X-Robots-Tag':'noindex', 'X-Content-Type-Options':'nosniff'});
      if (!['GET', 'HEAD'].includes(request.method)) return new Response(null, {status:405, headers:{...Object.fromEntries(headers), Allow:'GET, HEAD'}});
      // Чистый внутренний путь исключает автоматический редирект Cloudflare с .html.
      const assetUrl = new URL(yandexVerification.slice(0, -5), url);
      const asset = await env.ASSETS.fetch(new Request(assetUrl, {method:request.method}));
      return new Response(request.method === 'HEAD' ? null : asset.body, {status:asset.status, headers});
    }
    const seo = await seoResponse(request, env, config);
    if (seo) return seo.ok && seo.headers.get('Content-Type')?.includes('text/html') ? playerNavigation(request, seo, config) : seo;
    if (url.pathname === '/important-items' || url.pathname === '/important-items.html') {
      if (!['GET', 'HEAD'].includes(request.method)) return new Response('Method not allowed', {status:405, headers:{Allow:'GET, HEAD', ...privateHeaders}});
      const errorPage = (status, text) => new Response(request.method === 'HEAD' ? null : `<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Важные предметы</title><body><h1>Важные предметы</h1><p role="alert">${text}</p><a href="/important-items">Вернуться к списку</a></body></html>`, {status, headers:{...privateHeaders, 'Content-Type':'text/html; charset=utf-8'}});
      if (config) {
        try {
          const target = new URL('/api/content/important-items', config.origin); target.search = url.search;
          // Это публичная страница: пользовательские cookies и OAuth-заголовки не отправляются.
          const response = await fetch(target, {headers:{'X-Wiki-Proxy-Token':config.token, 'X-Wiki-Client-IP':request.headers.get('CF-Connecting-IP') || '', 'Accept':'application/json'}, redirect:'manual', cache:'no-store', signal:AbortSignal.timeout(15000)});
          if (response.status === 422) return errorPage(422, 'Некорректный поиск или фильтр. Поиск ограничен 160 символами.');
          if (response.status === 404) return errorPage(404, 'Такой страницы списка нет.');
          if (!response.ok || !response.headers.get('Content-Type')?.includes('application/json')) return errorPage(503, 'Справочник временно недоступен. Попробуйте позже.');
          const body = await response.json();
          if (typeof body.html !== 'string') return errorPage(503, 'Справочник временно недоступен.');
          return playerNavigation(request,new Response(request.method === 'HEAD' ? null : body.html, {headers:{'Content-Type':'text/html; charset=utf-8', 'Cache-Control':'public, max-age=0, must-revalidate', 'X-Content-Type-Options':'nosniff', 'Referrer-Policy':'strict-origin-when-cross-origin'}}),config);
        } catch { return errorPage(503, 'Справочник временно недоступен. Попробуйте позже.'); }
      }
      return errorPage(503, 'Справочник временно недоступен. Попробуйте позже.');
    }
    if (url.pathname === '/profile-runtime.mjs') {
      return new Response(`export const serverProfiles = ${!!config};\n`, {
        headers: {...privateHeaders, 'Content-Type': 'text/javascript; charset=utf-8'},
      });
    }
    if (url.pathname !== '/api' && !url.pathname.startsWith('/api/')) {
      const asset = await env.ASSETS.fetch(navigationAssetRequest(request));
      const match = url.pathname.match(/^\/quests\/(trader\/)?([a-zA-Z0-9_-]+)\/?$/);
      if (asset.status !== 404 || !config || !match || !['GET', 'HEAD'].includes(request.method)) return playerNavigation(request,asset,config);
      try {
        const lookup = new URL('/api/content/quest/' + match[2], config.origin);
        if(match[1])lookup.searchParams.set('trader','1');
        const response = await fetch(lookup, {headers: {'X-Wiki-Proxy-Token': config.token,
          'X-Wiki-Client-IP': request.headers.get('CF-Connecting-IP') || ''}, redirect: 'manual', cache: 'no-store', signal: AbortSignal.timeout(7000)});
        if (!response.ok || !(await response.json()).exists) return playerNavigation(request,asset,config);
        const shell = new URL(match[1]?'/quests':'/quest', url);
        const page = await env.ASSETS.fetch(new Request(shell, {method: request.method}));
        const headers = new Headers(page.headers);headers.set('Cache-Control', 'private, no-store');
        return playerNavigation(request,new Response(page.body, {status: page.status, headers}),config);
      } catch { return playerNavigation(request,asset,config); }
    }
    if (!config) return unavailable();
    // Origin, CSRF и opaque cookie проверяет Laravel; клиентские proxy-заголовки заменяем.
    const headers = new Headers(request.headers);
    for (const name of ['host', 'forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-wiki-proxy-token', 'x-wiki-client-ip']) headers.delete(name);
    headers.set('X-Wiki-Proxy-Token', config.token);
    headers.set('X-Wiki-Client-IP', request.headers.get('CF-Connecting-IP') || '');
    const target = new URL(config.origin);
    target.pathname = url.pathname; target.search = url.search;
    try {
      const response = await fetch(target, {
        method: request.method, headers, body: ['GET', 'HEAD'].includes(request.method) ? undefined : request.body,
        // cacheTtl: 0 всё ещё принудительно кэширует и может удалить Set-Cookie.
        redirect: 'manual', signal: AbortSignal.timeout(7000), cache: 'no-store',
      });
      // Не пересылаем секрет при редиректе и не выдаём HTML страницы ошибок вместо JSON.
      if (response.status >= 300 && response.status < 400 || !response.headers.get('Content-Type')?.includes('application/json')) return unavailable();
      const outgoing = new Headers(response.headers);
      for (const [name, value] of Object.entries(privateHeaders)) outgoing.set(name, value);
      outgoing.set('X-Robots-Tag', 'noindex, nofollow');
      outgoing.delete('Access-Control-Allow-Origin'); outgoing.delete('Access-Control-Allow-Credentials');
      return new Response(response.body, {status: response.status, headers: outgoing});
    } catch { return unavailable(); }
  },
};
