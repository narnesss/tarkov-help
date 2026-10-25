import assert from 'node:assert/strict';
import {test} from 'node:test';
import {configureSiteRoutes} from '../../public/site-routes.mjs?v=page-content-20261017';
import {pageSeoURL,updatePageSeo} from '../../public/page-seo.mjs';

function page(t, href = 'https://wiki.test/quests/debut', canonical = href, serverContent = true) {
  const originalDocument = globalThis.document, originalLocation = globalThis.location;
  const nodes = [];
  class Element {
    constructor(tag) { this.tag = tag; this.attributes = {}; this.textContent = ''; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return this.attributes[name] ?? null; }
    hasAttribute(name) { return Object.hasOwn(this.attributes, name); }
    remove() { const index = nodes.indexOf(this); if (index !== -1) nodes.splice(index, 1); }
    get content() { return this.getAttribute('content'); }
  }
  const matches = (node, selector) => {
    const match = /^(\w+)(?:\[([\w-]+)(?:="([^"]*)")?\])?$/.exec(selector);
    return Boolean(match && node.tag === match[1] && (!match[2] || match[3] === undefined ? !match[2] || Object.hasOwn(node.attributes, match[2]) : node.getAttribute(match[2]) === match[3]));
  };
  const doc = {head:{append:node => nodes.push(node)}, body:new Element('body'), createElement:tag => new Element(tag),
    querySelector:selector => nodes.find(node => matches(node, selector)) ?? null,
    querySelectorAll:selector => nodes.filter(node => matches(node, selector)), nodes};
  if (serverContent) doc.body.setAttribute('data-server-content', 'true');
  const add = (tag, attributes = {}, text = '') => { const node = new Element(tag); node.attributes = attributes; node.textContent = text; nodes.push(node); return node; };
  add('meta', {name:'wiki-runtime', content:'php'});
  add('title', {}, 'Подробный серверный заголовок');
  add('meta', {name:'description', content:'Полное серверное описание целей и наград'});
  if (canonical) add('link', {rel:'canonical', href:canonical});
  const schema = add('script', {type:'application/ld+json'}, JSON.stringify({'@context':'https://schema.org', '@graph':[{'@type':'WebPage', url:canonical, name:'Серверные данные'}]}));
  globalThis.document = doc; globalThis.location = new URL(href);
  configureSiteRoutes({quests:{q1:{slug:'debut'}, q2:{slug:'next-quest'}}, traders:{t1:{slug:'prapor'}}});
  t.after(() => { globalThis.document = originalDocument; globalThis.location = originalLocation; configureSiteRoutes({}); });
  return {doc, schema};
}

test('первый render сохраняет полный SSR title, description и JSON-LD при том же canonical', t => {
  const {doc, schema} = page(t), previousSchema = schema.textContent;
  const values = {title:'Короткий клиентский заголовок', description:'Короткое описание', canonical:'/quest.html?quest=q1&mode=pve'};
  assert.equal(updatePageSeo(values), false);
  assert.equal(doc.querySelector('title').textContent, 'Подробный серверный заголовок');
  assert.equal(doc.querySelector('meta[name="description"]').content, 'Полное серверное описание целей и наград');
  assert.equal(schema.textContent, previousSchema);
  assert.equal(updatePageSeo(values), false);
  assert.equal(schema.textContent, previousSchema);
});

test('статическая карточка при том же canonical обновляет устаревшие метаданные из API', t => {
  const url = 'https://wiki.test/reference/items/item-id';
  const {doc} = page(t, url, url, false);
  const title = 'Новое название предмета — предмет Escape from Tarkov | Tarkov Compass';
  assert.equal(updatePageSeo({title, description:'Исправленное описание предмета', canonical:url}), true);
  assert.equal(doc.querySelector('title').textContent, title);
  assert.equal(doc.querySelector('meta[name="description"]').content, 'Исправленное описание предмета');
  assert.equal(doc.querySelector('meta[property="og:title"]').content, title);
  assert.equal(doc.querySelector('meta[name="twitter:title"]').content, title);
  const entity = JSON.parse(doc.querySelector('script[data-page-seo]').textContent)['@graph'].find(node => node['@type'] === 'WebPage');
  assert.equal(entity.name, title);
  assert.equal(entity.description, 'Исправленное описание предмета');
  assert.equal(entity.url, url);
});

test('SPA-переход меняет canonical, title, OG/Twitter и WebPage/Breadcrumb одновременно', t => {
  const {doc} = page(t);
  updatePageSeo({title:'Проба пера', canonical:'/quests/debut'});
  globalThis.location = new URL('https://wiki.test/quests/next-quest?mode=regular&utm_source=ignored');
  const title = 'Следующий квест — квест Escape from Tarkov — PVP | Tarkov Compass';
  assert.equal(updatePageSeo({title, description:'Найти документ', canonical:location.href, breadcrumbs:[{name:'Квесты', url:'/quests?mode=regular'}, {name:'Следующий квест', url:location.href}]}), true);
  const canonical = 'https://wiki.test/quests/next-quest?mode=regular';
  assert.equal(doc.querySelector('link[rel="canonical"]').getAttribute('href'), canonical);
  assert.equal(doc.querySelector('title').textContent, title);
  for (const selector of ['meta[property="og:title"]', 'meta[name="twitter:title"]']) assert.equal(doc.querySelector(selector).content, title);
  for (const selector of ['meta[name="description"]', 'meta[property="og:description"]', 'meta[name="twitter:description"]']) assert.equal(doc.querySelector(selector).content, 'Найти документ');
  assert.equal(doc.querySelector('meta[property="og:url"]').content, canonical);
  const graph = JSON.parse(doc.querySelector('script[data-page-seo]').textContent)['@graph'];
  assert.equal(graph.find(node => node['@type'] === 'WebPage').url, canonical);
  assert.equal(graph.find(node => node['@type'] === 'BreadcrumbList').itemListElement.at(-1).item, canonical);
  assert.equal(doc.querySelectorAll('script[type="application/ld+json"]').length, 1);
});

test('текст с HTML не создаёт активные узлы или новый script', t => {
  const {doc} = page(t, 'https://wiki.test/quests/debut', null);
  const attack = '</script><script>alert(1)</script><img src=x onerror=alert(2)> & "имя"';
  updatePageSeo({title:attack, description:attack, canonical:'/quests/debut', breadcrumbs:[{name:attack, url:'/quests/debut'}]});
  assert.equal(doc.querySelector('title').textContent, attack);
  assert.equal(doc.querySelector('meta[name="description"]').content, attack);
  assert.equal(doc.querySelectorAll('img').length, 0);
  assert.equal(doc.querySelectorAll('script').length, 1);
  const source = doc.querySelector('script[data-page-seo]').textContent;
  assert.doesNotMatch(source, /<\/script>/);
  assert.equal(JSON.parse(source)['@graph'].find(node => node['@type'] === 'WebPage').name, attack);
});

test('канонические адреса разделяют PVP и пагинацию, удаляют фильтры и не принимают внешний URL', t => {
  page(t);
  assert.equal(pageSeoURL('/quest.html?quest=q1&mode=regular&tab=rewards#map'), 'https://wiki.test/quests/debut?mode=regular');
  assert.equal(pageSeoURL('/quests/all?mode=regular&page=2&q=filter'), 'https://wiki.test/quests/all?mode=regular&page=2');
  assert.equal(pageSeoURL('/quests/debut?mode=pve&page=2'), 'https://wiki.test/quests/debut');
  assert.equal(pageSeoURL('/reference/items/123?mode=regular&q=filter'), 'https://wiki.test/reference/items/123?mode=regular');
  assert.equal(pageSeoURL('/boss/killa?mode=regular'), 'https://wiki.test/boss/killa?mode=regular');
  assert.equal(pageSeoURL('/reference/items?mode=regular&page=2&q=text'), 'https://wiki.test/reference/items?mode=regular&page=2');
  assert.equal(pageSeoURL('/raid/shoreline?mode=regular&floor=all'), 'https://wiki.test/raid/shoreline?mode=regular');
  for (const canonical of ['https://evil.test/quests/debut', 'javascript:alert(1)', 'https://user:secret@wiki.test/quests/debut']) assert.equal(updatePageSeo({title:'Чужой URL', canonical}), false);
});

test('пропавший квест после SPA-перехода получает noindex без устаревшей разметки', t => {
  const {doc} = page(t);
  updatePageSeo({title:'Проба пера', canonical:'/quests/debut'});
  globalThis.location = new URL('https://wiki.test/quests/missing');
  assert.equal(updatePageSeo({title:'Квест не найден | Tarkov Compass', robots:'noindex, follow'}), true);
  assert.equal(doc.querySelector('meta[name="robots"]').content, 'noindex, follow');
  assert.equal(doc.querySelector('link[rel="canonical"]').getAttribute('href'), location.href);
  assert.equal(doc.querySelectorAll('script[type="application/ld+json"]').length, 0);
});
