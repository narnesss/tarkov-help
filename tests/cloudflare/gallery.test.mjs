import assert from 'node:assert/strict';
import {test} from 'node:test';
import {openGallery,galleryBlock} from '../../public/gallery.mjs';

test('общая галерея восстанавливает фото и изолирует запоздавшие загрузки',async t=>{
 const previousDocument=globalThis.document,previousLocation=globalThis.location;
 class Element {
  children=[];listeners={};hidden=false;attributes={};
  constructor(tag){this.tagName=tag;}
  append(...nodes){for(const node of nodes){node.parent=this;this.children.push(node);}}
  replaceChildren(...nodes){this.children=[];this.append(...nodes);}
  replaceWith(node){const parent=this.parent;parent.children[parent.children.indexOf(this)]=node;node.parent=parent;}
  setAttribute(name,value){this.attributes[name]=value;}
  addEventListener(name,callback){this.listeners[name]=callback;}
  showModal(){this.open=true;}
  close(){this.open=false;this.listeners.close?.();}
  focus(){globalThis.document.activeElement=this;}
 }
 const body=new Element('body');
 const find=(node,id)=>node.id===id?node:node.children.map(child=>find(child,id)).find(Boolean);
 globalThis.document={body,baseURI:'https://wiki.test/',createElement:tag=>new Element(tag),createDocumentFragment:()=>new Element('fragment'),getElementById:id=>find(body,id),activeElement:new Element('button')};
 globalThis.location={href:'https://wiki.test/quests/wiring-the-vessel?mode=pve'};
 const get=id=>document.getElementById(id),photo=()=>get('gallery-full');
 const frames=[{fullUrl:'https://images.test/full.webp',url:'assets/preview.webp',caption:'Первое фото'},
  {fullUrl:'/assets/second.webp',url:'/assets/second.webp',caption:'Второе фото'}];
 t.mock.timers.enable({apis:['setTimeout']});
 try{
  await t.test('отказ оригинала открывает превью, подпись и навигация сохраняются',()=>{
   openGallery(frames);assert.equal(photo().src,frames[0].fullUrl);assert.equal(photo().hidden,true);
   photo().onerror();assert.equal(photo().src,'https://wiki.test/assets/preview.webp');photo().onload();
   assert.equal(photo().hidden,false);assert.equal(get('gallery-status').textContent,'');
   assert.equal(get('gallery-caption').textContent,'1 / 2 · Первое фото');assert.equal(get('gallery-retry').hidden,true);
  });
  await t.test('устаревшие события не меняют выбранный кадр и его ошибку',()=>{
   openGallery(frames);const oldError=photo().onerror,oldLoad=photo().onload;
   get('gallery-next').onclick();photo().onerror();const status=get('gallery-status').textContent;
   oldError();oldLoad();assert.equal(photo().src,'https://wiki.test/assets/second.webp');
   assert.equal(photo().hidden,true);assert.equal(get('gallery-status').textContent,status);assert.equal(get('gallery-retry').hidden,false);
  });
  await t.test('после двух отказов есть рабочий повтор без бесконечных попыток',()=>{
   openGallery(frames);photo().onerror();photo().onerror();assert.equal(photo().onerror,null);
   assert.match(get('gallery-status').textContent,/не загрузилось/);assert.equal(get('gallery-retry').hidden,false);
   get('gallery-retry').onclick();assert.equal(photo().src,frames[0].fullUrl);photo().onload();
   assert.equal(photo().hidden,false);assert.equal(get('gallery-retry').hidden,true);
  });
  await t.test('на каждый адрес отводится 15 секунд; старый ответ после таймаута игнорируется',()=>{
   openGallery(frames);const oldLoad=photo().onload;t.mock.timers.tick(15000);
   assert.equal(photo().src,'https://wiki.test/assets/preview.webp');oldLoad();assert.equal(photo().hidden,true);
   t.mock.timers.tick(15000);assert.equal(get('gallery-retry').hidden,false);assert.equal(photo().onerror,null);
  });
  await t.test('одинаковые URL не повторяются, пустые и опасные адреса не загружаются',()=>{
   for(const frame of [{url:'/same.webp',fullUrl:'same.webp'}, {url:'',fullUrl:null}, {url:'javascript:alert(1)',fullUrl:'data:text/html,bad'}]){
    openGallery([frame]);if(photo().onerror)photo().onerror();
    assert.equal(photo().onerror,null);assert.equal(get('gallery-retry').hidden,false);
    assert.ok(!photo().src||photo().src==='https://wiki.test/same.webp');
   }
   openGallery([{url:'/valid.webp',fullUrl:'javascript:alert(1)'}]);assert.equal(photo().src,'https://wiki.test/valid.webp');photo().onload();
  });
  await t.test('переход по миниатюре, стрелки, свайп и возврат фокуса работают',()=>{
   const block=galleryBlock(frames,{association:'objective'}),button=block.children[1].children[1];
   button.focus();button.onclick();assert.equal(get('gallery-caption').textContent,'2 / 2 · Второе фото');
   const dialog=body.children[0],stage=photo().parent;
   dialog.listeners.keydown({key:'ArrowLeft',preventDefault(){}});assert.equal(photo().alt,'Первое фото');
   stage.listeners.touchstart({touches:[{clientX:200}]});stage.listeners.touchend({changedTouches:[{clientX:100}]});
   assert.equal(photo().alt,'Второе фото');dialog.close();t.mock.timers.tick(30000);
   assert.equal(document.activeElement,button);assert.equal(get('gallery-retry').hidden,true);
  });
 }finally{
  body.children[0]?.close();t.mock.timers.reset();
  if(previousDocument===undefined)delete globalThis.document;else globalThis.document=previousDocument;
  if(previousLocation===undefined)delete globalThis.location;else globalThis.location=previousLocation;
 }
});
