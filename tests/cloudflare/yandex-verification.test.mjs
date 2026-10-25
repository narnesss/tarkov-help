import assert from 'node:assert/strict';
import fs from 'node:fs';
import {test} from 'node:test';
import worker from '../../cloudflare/worker.mjs';

const origin='https://tarkov-compass.narnesss.workers.dev';
const path='/yandex_78b9cda6dd2aaa25.html';
const html=fs.readFileSync(new URL('../../public'+path,import.meta.url),'utf8');

test('файл Яндекса отдаётся по точному .html без редиректа, API и личной шапки',async()=>{
  const original=globalThis.fetch;
  globalThis.fetch=async()=>{throw Error('Проверка владения не должна обращаться к API');};
  try{
    for(const route of [path,path.slice(0,-5)]){
      const response=await worker.fetch(new Request(origin+route+'?test=1',{headers:{Cookie:'eft_session=private',Authorization:'secret'}}),{ASSETS:{fetch:async request=>{
        assert.equal(new URL(request.url).pathname,path.slice(0,-5));assert.equal(new URL(request.url).search,'');assert.equal(request.headers.has('Cookie'),false);assert.equal(request.headers.has('Authorization'),false);
        return new Response(html);
      }}});
      assert.equal(response.status,200);assert.equal(response.headers.has('Location'),false);assert.match(response.headers.get('Content-Type'),/text\/html/);assert.equal(response.headers.get('X-Robots-Tag'),'noindex');assert.equal(await response.text(),html);
    }
  }finally{globalThis.fetch=original;}
});

test('HEAD проверки владения пустой, остальные методы отклоняются до ASSETS',async()=>{
  let calls=0;
  const env={ASSETS:{fetch:async request=>{calls++;assert.equal(request.method,'HEAD');return new Response(null);}}};
  const head=await worker.fetch(new Request(origin+path,{method:'HEAD'}),env);
  assert.equal(head.status,200);assert.equal(await head.text(),'');
  const post=await worker.fetch(new Request(origin+path,{method:'POST'}),env);
  assert.equal(post.status,405);assert.equal(post.headers.get('Allow'),'GET, HEAD');assert.equal(calls,1);
});

test('проверочный файл соответствует исходнику и экспорту, исключён из sitemap',()=>{
  const read=name=>fs.readFileSync(new URL('../../'+name,import.meta.url),'utf8').replaceAll('\r\n','\n');
  assert.equal(html.replaceAll('\r\n','\n'),read('resources/views/verification/yandex.blade.php'));
  assert.equal(html.replaceAll('\r\n','\n'),read('dist'+path));assert.match(html,/<body>Verification: 78b9cda6dd2aaa25<\/body>/);
  assert.doesNotMatch(read('dist/sitemaps/pages.xml'),/yandex_78b9cda6dd2aaa25/);
  const config=JSON.parse(read('wrangler.jsonc'));
  assert.ok(config.assets.run_worker_first.includes(path));assert.ok(config.assets.run_worker_first.includes(path.slice(0,-5)));
});
