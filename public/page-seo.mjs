import {canonicalURL,pageParams} from './site-routes.mjs?v=page-content-20261017';

const pageStates = new WeakMap();
const shortText = value => Array.from(String(value ?? '').replace(/\s+/gu, ' ').trim()).slice(0, 230).join('');

/** Убирает фильтры из canonical, сохраняя отдельные режимы и страницы каталогов. */
export function pageSeoURL(value = globalThis.location.href) {
  const url = canonicalURL(value), current = new URL(globalThis.location.href);
  if (!['http:', 'https:'].includes(url.protocol) || url.origin !== current.origin || url.username || url.password) return null;
  const params = pageParams(url), content = /^\/(?:quests?|reference|boss|raid|documents)(?:[/.]|$)/.test(url.pathname);
  const detail = ['quest', 'item', 'boss', 'map'].some(key => params.has(key));
  url.hash = ''; url.search = '';
  if (content && params.get('mode') === 'regular') url.searchParams.set('mode', 'regular');
  if (content && !detail && /^[1-9][0-9]{0,3}$/.test(params.get('page') || '') && Number(params.get('page')) > 1) url.searchParams.set('page', params.get('page'));
  return url.href;
}

/** Синхронизирует метаданные при переходах без перезагрузки; содержимое задаётся только как текст. */
export function updatePageSeo({title, description, canonical, breadcrumbs, robots = 'index, follow'}) {
  const doc = globalThis.document;
  if (!doc?.head || typeof title !== 'string') return false;
  let url;
  try { url = pageSeoURL(canonical); } catch { return false; }
  if (!url) return false;
  const signature = JSON.stringify([url, title, description, robots]);
  const previous = pageStates.get(doc), existingCanonical = doc.querySelector('link[rel="canonical"]')?.getAttribute('href');
  pageStates.set(doc, signature);
  // Сохраняем первый живой SSR; статический экспорт обновляется вместе с данными из API.
  if (previous === signature || previous === undefined && doc.body?.hasAttribute('data-server-content') && existingCanonical === url && robots === 'index, follow') return false;
  function element(tag, attribute, value) {
    const selector = attribute ? `${tag}[${attribute}="${value}"]` : tag;
    let node = doc.querySelector(selector);
    if (!node) { node = doc.createElement(tag); if (attribute) node.setAttribute(attribute, value); doc.head.append(node); }
    return node;
  }
  const text = shortText(description ?? title);
  element('title').textContent = title;
  element('link', 'rel', 'canonical').setAttribute('href', url);
  for (const [name, value] of [['description', text], ['robots', robots], ['twitter:title', title], ['twitter:description', text]]) element('meta', 'name', name).setAttribute('content', value);
  for (const [name, value] of [['og:title', title], ['og:description', text], ['og:url', url], ['og:type', 'website']]) element('meta', 'property', name).setAttribute('content', value);
  let script = doc.querySelector('script[data-page-seo]');
  if (!script) script = [...doc.querySelectorAll('script[type="application/ld+json"]')].find(node => {
    try { const data = JSON.parse(node.textContent); return data['@type'] === 'WebPage' || data['@graph']?.some(item => item['@type'] === 'WebPage'); } catch { return false; }
  });
  if (robots.startsWith('noindex')) { script?.remove(); return true; }
  if (!script) { script = doc.createElement('script'); script.setAttribute('type', 'application/ld+json'); doc.head.append(script); }
  script.setAttribute('data-page-seo', '');
  const origin = new URL(url).origin;
  const crumbs = [{name:'Tarkov Compass', url:origin + '/'}];
  for (const crumb of breadcrumbs ?? [{name:title, url}]) {
    let target;
    try { target = pageSeoURL(crumb.url); } catch { continue; }
    if (target && typeof crumb.name === 'string' && crumbs.at(-1).url !== target) crumbs.push({name:crumb.name, url:target});
  }
  script.textContent = JSON.stringify({'@context':'https://schema.org', '@graph':[
    {'@type':'WebSite', '@id':origin + '/#website', url:origin + '/', name:'Tarkov Compass', inLanguage:'ru'},
    {'@type':'WebPage', '@id':url + '#webpage', url, name:title, description:text, inLanguage:'ru', isPartOf:{'@id':origin + '/#website'}},
    {'@type':'BreadcrumbList', itemListElement:crumbs.map((crumb, index) => ({'@type':'ListItem', position:index + 1, name:crumb.name, item:crumb.url}))},
  ]}).replace(/</g, '\\u003c');
  return true;
}
