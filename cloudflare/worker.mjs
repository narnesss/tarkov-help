import {navigationAssetRequest,playerNavigation} from './player-navigation.mjs';

/** Статика остаётся на Cloudflare; приватный API исполняется только на alwaysdata. */
function settings(env) {
  let origin;
  try { origin = new URL(env.API_ORIGIN); } catch { return null; }
  if (env.API_ENABLED !== 'true' || typeof env.API_PROXY_TOKEN !== 'string' || env.API_PROXY_TOKEN.length < 32
    || origin.protocol !== 'https:' || origin.username || origin.password || origin.pathname !== '/' || origin.search || origin.hash) return null;
  return {origin, token: env.API_PROXY_TOKEN};
}

const privateHeaders = {'Cache-Control': 'private, no-store', 'X-Content-Type-Options': 'nosniff'};
function unavailable() {
  return Response.json({error: 'Синхронизация временно недоступна. Данные в браузере сохранены.'}, {status: 503, headers: privateHeaders});
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url), config = settings(env);
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
      outgoing.delete('Access-Control-Allow-Origin'); outgoing.delete('Access-Control-Allow-Credentials');
      return new Response(response.body, {status: response.status, headers: outgoing});
    } catch { return unavailable(); }
  },
};
