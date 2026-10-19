import {test} from 'node:test';import assert from 'node:assert/strict';
import {createDocumentOverlayLoader,visibleDocumentExits,accessLabel,fitDocumentOverlayView,documentOverlayFloors} from '../../public/document-overlays.mjs';
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

test('проверенные пограничные выходы видны без зажима координат; обзор оставляет поле',()=>{
 const exits=[{id:'signal',x:52,y:-2.91,floor:'floor-1',outsideArtwork:true},{id:'gates',x:56,y:100.42,floor:'floor-1',outsideArtwork:true},
 {id:'unverified',x:52,y:-2.91,floor:'floor-1'},{id:'foreign',x:223,y:50,floor:'floor-1',outsideArtwork:true}];
 assert.deepEqual(visibleDocumentExits({mapId:'customs',mode:'pve',exits},'customs','pve','floor-1').map(p=>p.id),['signal','gates']);
 for(const width of [320,390,768,1024,1280,1440]){const view=fitDocumentOverlayView(width,600,2600,1414,18);
  for(const point of exits.slice(0,2)){const x=view.x+point.x/100*2600*view.scale,y=view.y+point.y/100*1414*view.scale;assert.ok(x>=22&&x<=width-22&&y>=22&&y<=578);}}
});

test('нижний уровень с выходами доступен даже без документов; чужие и пустые уровни не добавляются',()=>{
 const map={id:'lab',documentPanels:{'floor--1':{},'floor-1':{},'floor-2':{}}};const points=[{floor:'floor-1'},{floor:'floor-2'}];
 assert.deepEqual(documentOverlayFloors(points,map,{mapId:'lab',exits:[{floor:'floor--1'},{floor:'floor-99'}]}),['floor--1','floor-1','floor-2']);
 assert.deepEqual(documentOverlayFloors(points,map,{mapId:'customs',exits:[{floor:'floor--1'}]}),['floor-1','floor-2']);
 assert.deepEqual(documentOverlayFloors(points,map,null,'floor--1'),['floor--1','floor-1','floor-2']);
});
