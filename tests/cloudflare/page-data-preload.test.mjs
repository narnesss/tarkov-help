import assert from 'node:assert/strict';
import {test} from 'node:test';
import {createJsonPreloader} from '../../public/json-preload.mjs';
import {preloadPageData} from '../../public/page-data-preload.mjs';

test('публичный запрос переиспользуется один раз и не подменяет ручное обновление',async()=>{
  let calls=0;const cache=createJsonPreloader(async key=>{calls++;return {key};});
  cache.preload('catalog');cache.preload('catalog');
  assert.deepEqual(await cache.take('catalog'),{key:'catalog'});assert.equal(calls,1);assert.equal(cache.take('catalog'),undefined);
  cache.preload('catalog');await cache.take('catalog');assert.equal(calls,2);
});
test('неудачная предзагрузка не мешает повторному запросу',async()=>{
  let calls=0;const cache=createJsonPreloader(async()=>{if(++calls===1)throw Error('offline');return 'fresh';});
  cache.preload('catalog');await assert.rejects(cache.take('catalog'),/offline/);
  cache.preload('catalog');assert.equal(await cache.take('catalog'),'fresh');
});
test('предзагружаются только публичные данные нужного режима, без профиля и прогресса',()=>{
  const paths=[];const run=(page,query)=>preloadPageData(page,{params:new URLSearchParams(query),preload:url=>paths.push(url)});
  run('quests','mode=pve');run('reference','mode=regular');run('raid','mode=pve&map=interchange');
  run('account','mode=pve');run('raid','mode=pve&map=../../state');run('quests','mode=unknown');run('quests','');
  assert.deepEqual(paths,['data/guide/quests-pve.json','data/guide/reference-regular.json','data/raid/pve/interchange.json']);
});
