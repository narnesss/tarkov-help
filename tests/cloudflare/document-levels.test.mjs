import test from 'node:test';import assert from 'node:assert/strict';import fs from 'node:fs';
import {documentViewport,documentPointPosition,documentLayerStyle,documentFloors} from '../../public/document-map.mjs';
import {filterSpawns} from '../../public/model.mjs';
import {CATEGORIES} from '../../public/raid-model.mjs';
const read=name=>JSON.parse(fs.readFileSync(new URL('../../public/data/'+name+'.json',import.meta.url)));
const maps=read('maps'),spawns=read('spawns').filter(p=>!p.cmsArchived),reference=read('document-point-provenance');

test('355 прежних точек сохраняют координаты Kord после перехода от панелей к единому плану',()=>{
 let count=0;for(const map of maps){const box=reference.maps[map.id].viewBox,viewport=documentViewport(map);
  assert.ok(Math.abs(viewport.width/viewport.height-box[2]/box[3])<.002,map.id);
  for(const p of spawns.filter(p=>p.map===map.id)){const xy=documentPointPosition(p,map),source=reference.maps[map.id].points.find(s=>s.id===p.id);
   assert.ok(xy.x>=0&&xy.x<=100&&xy.y>=0&&xy.y<=100,p.id);
   assert.ok(Math.abs((xy.x/100*box[2]+box[0])-source.sourceX)<.00001,p.id+' x');
   assert.ok(Math.abs((xy.y/100*box[3]+box[1])-source.sourceY)<.00001,p.id+' y');count++;
  }
 }assert.equal(count,355);
});

test('каждая из 12 подложек содержит один SVG и исходные переключаемые слои без копий схемы',()=>{
 for(const map of maps){const svg=fs.readFileSync(new URL('../../public/'+map.layeredFile,import.meta.url),'utf8');
  assert.equal([...svg.matchAll(/<svg(?=\s|>)/g)].length,1,map.id);
  assert.doesNotMatch(svg,/Уровень схемы|id="p\d+-floor-/);
  for(const level of Object.keys(map.documentPanels))assert.ok(svg.includes(`id="${level}"`),map.id+' '+level);
 }
});

test('список этажей содержит только документы, каждый уровень показывает ровно свои исходные точки Kord',()=>{
 let levels=0,total=0;
 for(const map of maps){const local=spawns.filter(p=>p.map===map.id),floors=documentFloors(local);
  const source=reference.maps[map.id].points;
  assert.deepEqual(floors,[...new Set(source.map(p=>p.floor))].sort((a,b)=>Number(a.replace('floor-',''))-Number(b.replace('floor-',''))));
  const shown=[];
  for(const floor of floors){const filtered=filterSpawns(spawns,{mapId:map.id,floor});
   assert.deepEqual(filtered.map(p=>p.id).sort(),source.filter(p=>p.floor===floor).map(p=>p.id).sort(),map.id+' '+floor);
   assert.ok(filtered.length>0);shown.push(...filtered.map(p=>p.id));levels++;
  }
  assert.equal(shown.length,new Set(shown).size,'Нет дублей между уровнями '+map.id);
  assert.equal(shown.length,local.length);total+=shown.length;
 }
 assert.equal(total,355);assert.equal(levels,26);
 assert.deepEqual(documentFloors([{floor:'floor--1',cmsArchived:true},{floor:'floor-2'},{floor:'floor-2'},{floor:'floor-1'}]),['floor-1','floor-2']);
 assert.deepEqual(documentFloors([]),[]);
 assert.deepEqual(documentFloors(spawns.filter(p=>p.map==='customs')),['floor-1','floor-2','floor-3']);
});

test('начальная разметка документов не показывает карту и счётчики другой локации',()=>{
 const blade=fs.readFileSync(new URL('../../resources/views/pages/documents.blade.php',import.meta.url),'utf8');
 assert.doesNotMatch(blade,/assets\/maps\/interchange|Развязка|location-en/);
 assert.match(blade,/<img id="map-image"[^>]*hidden/);
 assert.doesNotMatch(blade,/<img id="(?:map-image|minimap-image|location-thumb)"[^>]*\bsrc=/);
 assert.match(blade,/<option value="" disabled selected>Локация<\/option>/);
});

test('этажи переключаются на одной плоскости: верхние скрываются, нижние затемняются; общий вид использует основной уровень',()=>{
 const ids=['floor--1','floor-0','floor-1','floor-2','floor-3'];
 const second=documentLayerStyle(ids,'floor-2');assert.match(second,/#floor-2\{display:inline!important;filter:none/);
 assert.match(second,/#floor-3\{display:none/);assert.match(second,/#floor-1\{display:inline!important;filter:brightness/);
 assert.equal(documentLayerStyle(ids,'all'),documentLayerStyle(ids,'floor-1'));
 assert.equal(documentLayerStyle(ids,'unknown'),documentLayerStyle(ids,'floor-1'));
 assert.doesNotMatch(documentLayerStyle([...ids,'floor-2}body{color:red'],'floor-1'),/body/);
 assert.deepEqual(documentPointPosition({x:12,y:34},{width:100,height:100}),{x:12,y:34});
 assert.equal(CATEGORIES.document,undefined,'Документы не добавляются на рейдовые карты');
});
