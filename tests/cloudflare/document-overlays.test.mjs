import {test} from 'node:test';import assert from 'node:assert/strict';
import {createDocumentOverlayLoader,visibleDocumentExits,accessLabel} from '../../public/document-overlays.mjs';
test('выходы другой карты, режима и этажа не смешиваются с документами',()=>{
 const data={mapId:'customs',mode:'pve',exits:[{id:'one',floor:'floor-1',x:20,y:30},{id:'other',floor:'floor-2',x:20,y:30},{id:'bad',floor:'floor-1',x:NaN,y:30}]};
 assert.deepEqual(visibleDocumentExits(data,'customs','pve','floor-1').map(p=>p.id),['one']);
 for(const args of [['woods','pve','all'],['customs','regular','all'],['customs','pve','all',false]])assert.equal(visibleDocumentExits(data,...args).length,0);
});
test('запоздалый ответ после смены локации или режима не возвращает чужие выходы',async()=>{
 const pending=[];const load=createDocumentOverlayLoader((map,mode,signal)=>new Promise(resolve=>pending.push({map,mode,signal,resolve})));
 const old=load('customs','pve'),fresh=load('woods','regular');assert.equal(pending[0].signal.aborted,true);
 pending[1].resolve({mapId:'woods',mode:'regular',exits:[],access:{}});assert.equal((await fresh).mapId,'woods');
 pending[0].resolve({mapId:'customs',mode:'pve',exits:[],access:{}});assert.equal(await old,null);
});
test('замок и подпись зависят от требования ключа; текст не становится HTML',()=>{
 assert.equal(accessLabel({requiresKey:false,keys:[{name:'Ключ'}]}),'');
 assert.equal(accessLabel({requiresKey:true,keys:[{name:'Ключ <script>'}]}),'Нужен ключ: Ключ <script>');
});
