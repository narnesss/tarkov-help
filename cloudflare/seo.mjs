const responseLimit = 2 * 1024 * 1024;
const publicHeaders = {'Cache-Control':'no-store', 'X-Content-Type-Options':'nosniff', 'Referrer-Policy':'strict-origin-when-cross-origin'};
const queryParameters = ['mode', 'page', 'quest', 'trader', 'section', 'item', 'boss', 'map', 'tab'];
const sitemapPaths = new Set(['/sitemap.xml', '/sitemaps/quests.xml', '/sitemaps/pages.xml', '/sitemaps/items-pve.xml', '/sitemaps/items-regular.xml']);

function errorPage(request, status) {
  const unavailable = status === 503;
  const title = unavailable ? 'Страница временно недоступна' : status === 405 ? 'Метод не поддерживается' : status === 422 ? 'Некорректный адрес страницы' : 'Страница не найдена';
  const headers = {...publicHeaders, 'Content-Type':'text/html; charset=utf-8', 'X-Robots-Tag':'noindex'};
  if (unavailable) headers['Retry-After'] = '60';
  if (status === 405) headers.Allow = 'GET, HEAD';
  return new Response(request.method === 'HEAD' ? null : `<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>${title}</title></head><body><h1>${title}</h1><p>${unavailable ? 'Не удалось загрузить актуальные данные. Попробуйте ещё раз позже.' : 'Проверьте адрес или откройте каталог квестов.'}</p><a href="/quests">Квесты Escape from Tarkov</a></body></html>`, {status, headers});
}

async function limitedJson(response, signal) {
  if (Number(response.headers.get('Content-Length')) > responseLimit || !response.body) {
    void response.body?.cancel().catch(() => {});
    throw new Error('Размер ответа SEO превышен или тело отсутствует');
  }
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let length = 0, text = '';
  let onAbort;
  const aborted = new Promise((_, reject) => {
    onAbort = () => reject(new Error('Истекло время ожидания SEO'));
    if (signal.aborted) onAbort();
    else signal.addEventListener('abort', onAbort, {once:true});
  });
  try {
    while (true) {
      const {value, done} = await Promise.race([reader.read(), aborted]);
      if (done) break;
      length += value.byteLength;
      if (length > responseLimit) throw new Error('Размер ответа SEO превышен');
      text += decoder.decode(value, {stream:true});
    }
    return JSON.parse(text + decoder.decode());
  } catch (error) {
    void reader.cancel().catch(() => {});
    throw error;
  } finally {
    signal.removeEventListener('abort', onAbort);
    reader.releaseLock();
  }
}

/** Публичный HTML и sitemap берутся из актуального backend без передачи личной сессии. */
export async function seoResponse(request, env, config) {
  const url = new URL(request.url), path = url.pathname;
  const contentRoute = /^\/(?:quests?|reference|boss|raid|documents)(?:[/.]|$)/.test(path);
  const sitemapRoute = path === '/sitemap.xml' || path.startsWith('/sitemaps/');
  if (!contentRoute && !sitemapRoute && path !== '/robots.txt') return null;
  if (!['GET', 'HEAD'].includes(request.method)) return errorPage(request, 405);
  if (path === '/robots.txt') {
    const asset = await env.ASSETS.fetch(new Request(new URL('/robots.txt', url), {method:request.method}));
    const headers = new Headers(asset.headers);
    headers.set('Content-Type', 'text/plain; charset=utf-8');
    headers.set('X-Content-Type-Options', 'nosniff');
    return new Response(request.method === 'HEAD' ? null : asset.body, {status:asset.status, headers});
  }
  if (path.length > 512 || (sitemapRoute ? !sitemapPaths.has(path) : !/^\/(?:quests?(?:\/(?:trader\/)?[a-zA-Z0-9_-]+)?|reference(?:\/[a-zA-Z0-9_-]+){0,2}|(?:boss|raid|documents)(?:\/[a-zA-Z0-9_-]+)?)(?:\.html)?\/?$/.test(path))) return errorPage(request, 404);
  if (!config) return env.API_ENABLED === 'true' ? errorPage(request, 503) : null;
  try {
    const target = new URL('/api/content/seo', config.origin);
    target.searchParams.set('path', sitemapRoute ? path : path.replace(/\/$/, '').replace(/\.html$/, ''));
    for (const name of queryParameters) {
      if (url.searchParams.has(name)) target.searchParams.set(name, url.searchParams.get(name));
    }
    const signal = AbortSignal.timeout(7000);
    const response = await fetch(target, {method:'GET', headers:{
      'X-Wiki-Proxy-Token':config.token, 'X-Wiki-Client-IP':request.headers.get('CF-Connecting-IP') || '', 'Accept':'application/json',
    }, credentials:'omit', redirect:'manual', cache:'no-store', signal});
    if (![200, 404, 422].includes(response.status) || !response.headers.get('Content-Type')?.includes('application/json')) {
      void response.body?.cancel().catch(() => {});
      return errorPage(request, 503);
    }
    const body = await limitedJson(response, signal);
    // Старый API не знает endpoint: сохраняем прежний frontend на время смешанного релиза.
    if (response.status === 404 && body && typeof body === 'object' && !Array.isArray(body) && body.seoVersion !== 1) return null;
    if (response.status === 422) return errorPage(request, 422);
    const field = sitemapRoute ? 'xml' : 'html';
    if (!body || body.seoVersion !== 1 || ![200, 404].includes(body.status)
      || response.status === 404 && body.status !== 404 || typeof body[field] !== 'string' || !body[field].trim()
      || sitemapRoute && body.status !== 200) return errorPage(request, 503);
    const headers = {...publicHeaders, 'Content-Type':sitemapRoute ? 'application/xml; charset=utf-8' : 'text/html; charset=utf-8'};
    if (body.status === 404) headers['X-Robots-Tag'] = 'noindex';
    return new Response(request.method === 'HEAD' ? null : body[field], {status:body.status, headers});
  } catch { return errorPage(request, 503); }
}
