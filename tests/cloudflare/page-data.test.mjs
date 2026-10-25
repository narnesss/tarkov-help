import test from 'node:test';
import assert from 'node:assert/strict';
import {pageDataset,preloadPageView} from '../../public/page-data.mjs';

test('сводка ЛК запрашивает убежище и достижения как catalog в обоих режимах',async t=>{
  const calls=[];
  t.mock.method(globalThis,'fetch',async(path,options)=>{
    const url=new URL(path,'https://example.test');calls.push(url);
    assert.equal(options.credentials,'omit');assert.equal(options.cache,'no-store');
    if(url.searchParams.get('view')!=='catalog')return Response.json({error:'section требует catalog'},{status:422});
    const section=url.searchParams.get('section');
    return Response.json({[section]:[{id:section+'-id'}]});
  });
  for(const mode of ['regular','pve'])for(const section of ['hideout','achievements']){
    const rows=await pageDataset(`account-${section}-${mode}`,`data/guide/reference-${mode}.json`,{section});
    assert.deepEqual(rows[section],[{id:section+'-id'}]);
  }
  assert.deepEqual(calls.map(url=>url.pathname+url.search),['regular','pve'].flatMap(mode=>['hideout','achievements'].map(section=>`/api/content/data/guide/reference-${mode}.json?view=catalog&section=${section}`)));
});

test('после ошибки повторная загрузка сводки делает новый запрос с тем же разделом',async t=>{
  const calls=[];
  t.mock.method(globalThis,'fetch',async path=>{
    calls.push(path);return calls.length===1?Response.json({error:true},{status:503}):Response.json({hideout:[]});
  });
  const load=()=>pageDataset('account-hideout-regular','data/guide/reference-regular.json',{section:'hideout'});
  await assert.rejects(load(),/Не удалось загрузить данные/);
  assert.deepEqual(await load(),{hideout:[]});
  assert.deepEqual(calls,Array(2).fill('/api/content/data/guide/reference-regular.json?view=catalog&section=hideout'));
});

test('предзагрузка раздела расходуется однократно, явный detail сохраняется',async t=>{
  const calls=[];
  t.mock.method(globalThis,'fetch',async path=>{calls.push(path);return Response.json({ok:true});});
  const path='data/guide/reference-pve.json';
  preloadPageView(path,{section:'achievements'});
  await pageDataset('account-achievements-pve',path,{view:'catalog',section:'achievements'});
  assert.equal(calls.length,1);
  await pageDataset('account-achievements-pve',path,{section:'achievements'});
  await pageDataset('item','data/guide/items-pve.json',{view:'detail',id:'item-id'});
  assert.deepEqual(calls,[...Array(2).fill('/api/content/data/guide/reference-pve.json?view=catalog&section=achievements'),'/api/content/data/guide/items-pve.json?view=detail&id=item-id']);
});
