import assert from 'node:assert/strict';
import {test} from 'node:test';
import {galleryVersions} from '../../scripts/version-gallery-resources.mjs';

test('изменение SEO обновляет импортируемый модуль и каждый зависящий entrypoint',()=>{
 const resources=new Map([['page-seo.mjs','export const title=1;'],['boss.mjs',"import './page-seo.mjs?v=old';"],['bootstrap.mjs',"import('./boss.mjs?v=old');"],['gallery.mjs',''],['unrelated.mjs','']]);
 const roots=['gallery.mjs','page-seo.mjs'],before=galleryVersions(resources,roots);
 resources.set('page-seo.mjs','export const title=2;');const after=galleryVersions(resources,roots);
 for(const name of ['page-seo.mjs','boss.mjs','bootstrap.mjs'])assert.notEqual(after.get(name),before.get(name));
 assert.equal(after.get('gallery.mjs'),before.get('gallery.mjs'));assert.equal(after.has('unrelated.mjs'),false);
});

test('версии галереи меняются по транзитивным зависимостям и CSS, повторная сборка стабильна',()=>{
 const sources=new Map([['gallery.mjs',"import './common.mjs?v=old';"],['common.mjs','export const value=1;'],['guide.css','.gallery-full{display:block}'],
  ['quests.mjs',"import './gallery.mjs?v=old';"],['bootstrap.mjs',"import('./quests.mjs?v=old');"],['unrelated.mjs','']]);
 const before=galleryVersions(sources);
 assert.deepEqual([...before.keys()].sort(),['bootstrap.mjs','gallery.mjs','quests.mjs']);
 const stamped=new Map([...sources].map(([name,source])=>[name,source.replaceAll('v=old','v=abcdef')]));
 assert.deepEqual(galleryVersions(stamped),before);
 for(const dependency of ['common.mjs','guide.css']){
  const changed=new Map(sources);changed.set(dependency,sources.get(dependency)+' /* Изменение */');
  const after=galleryVersions(changed);for(const [name,version] of before)assert.notEqual(after.get(name),version,name);
 }
 const windows=new Map([...sources].map(([name,source])=>[name,source+'\r\n']));
 const unix=new Map([...sources].map(([name,source])=>[name,source+'\n']));
 assert.deepEqual(galleryVersions(windows),galleryVersions(unix));
});
