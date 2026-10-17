import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import {gunzipSync} from 'node:zlib';
import {safeURL,image} from '../../public/guide-common.mjs';

test('относительные медиа используют base страницы, а не вложенный адрес квеста',()=>{
  const previousDocument=globalThis.document,previousLocation=globalThis.location;
  const origin='https://wiki.example';
  const localization=JSON.parse(gunzipSync(fs.readFileSync(new URL('../../public/data/guide/media-localization.json.gz',import.meta.url))));
  try{
    globalThis.document={baseURI:origin+'/',createElement:()=>({})};
    for(const path of ['/quests/tour?mode=pve','/quests/batya?mode=regular','/quests/trader/prapor','/reference/items/example','/bosses/killa']){
      globalThis.location={href:origin+path};
      for(const frame of Object.values(localization.images)){
        if(!frame.url)continue;
        const expected=new URL(frame.url,origin+'/').href;
        assert.equal(safeURL(frame.url),expected,path);
        assert.equal(image(frame.url).src,expected,path);
      }
      assert.equal(safeURL('assets/achievements/reward.webp'),origin+'/assets/achievements/reward.webp');
      assert.equal(safeURL('/assets/quest-media-ru/photo.webp'),origin+'/assets/quest-media-ru/photo.webp');
      assert.equal(safeURL('https://source.example/photo.webp?version=2'), 'https://source.example/photo.webp?version=2');
      for(const unsafe of ['javascript:alert(1)','data:text/html,test','file:///tmp/photo'])assert.equal(safeURL(unsafe),'');
    }
    globalThis.document.baseURI=origin+'/preview/';
    assert.equal(safeURL('assets/photo.webp'),origin+'/preview/assets/photo.webp');
    delete globalThis.document;
    globalThis.location={href:origin+'/legacy.html'};
    assert.equal(safeURL('assets/photo.webp'),origin+'/assets/photo.webp');
  }finally{
    if(previousDocument===undefined)delete globalThis.document;else globalThis.document=previousDocument;
    if(previousLocation===undefined)delete globalThis.location;else globalThis.location=previousLocation;
  }
});
