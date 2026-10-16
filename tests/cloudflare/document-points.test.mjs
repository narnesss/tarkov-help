import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import {filterSpawns,sanitizeProgress,hasCoordinates} from '../../public/model.mjs';
const read=name=>JSON.parse(fs.readFileSync(new URL('../../public/data/'+name+'.json',import.meta.url)));
const maps=read('maps'),spawns=read('spawns'),source=read('source'),reference=read('document-point-provenance');

test('все 355 точек 12 карт совпадают с Kord по типу, описанию, этажу и координатам своей схемы',()=>{
 assert.equal(maps.length,12);const active=spawns.filter(p=>!p.cmsArchived);assert.equal(active.length,355);
 assert.equal(new Set(spawns.map(p=>p.id)).size,spawns.length);
 for(const map of maps){const ref=reference.maps[map.id],points=active.filter(p=>p.map===map.id);assert.equal(points.length,ref.count,map.id);
  assert.ok(fs.existsSync(new URL('../../public/'+map.file,import.meta.url)));
  for(const expected of ref.points){const p=points.find(p=>p.id===expected.id);assert.ok(p,map.id+' '+expected.id);
   assert.equal(p.sourcePointId,expected.sourceId);assert.equal(p.doc,expected.doc);assert.equal(p.floor,expected.floor);assert.equal(p.caption,expected.caption);assert.ok(hasCoordinates(p));assert.equal(hasCoordinates(p,true),false);
   const panel=map.documentPanels[p.floor],box=ref.viewBox;
   const x=((p.x-panel.x)/panel.width*box[2])+box[0],y=((p.y-panel.y)/panel.height*box[3])+box[1];
   assert.ok(Math.abs(x-expected.sourceX)<0.00001,p.id+' x');assert.ok(Math.abs(y-expected.sourceY)<0.00001,p.id+' y');
   assert.equal(crypto.createHash('sha256').update(fs.readFileSync(new URL('../../public/'+p.images[0],import.meta.url))).digest('hex'),expected.imageSha256);
  }
 }
});

test('второй этаж первого ангара Маяка содержит две точки, старая третья запись хранится в архиве',()=>{
 const points=filterSpawns(spawns,{mapId:'lighthouse'});
 const hangar=points.filter(p=>['1786283724981','1786283910600'].includes(p.sourcePointId));assert.equal(hangar.length,2);
 assert.ok(spawns.find(p=>p.id==='lighthouse-120').cmsArchived);
 assert.equal(points.some(p=>p.id==='lighthouse-120'),false);assert.equal(points.length,31);
});

test('архивные отметки и заметки сохраняются при загрузке и экспорте прогресса',()=>{
 const p=sanitizeProgress({version:1,found:['lighthouse-120','lighthouse-cmsobc7o4','unknown'],notes:{'lighthouse-120':'Личная заметка',unknown:'неверная'}},spawns.filter(p=>!p.cmsArchived),source.retiredPointIds);
 assert.deepEqual(p.found,['lighthouse-120','lighthouse-cmsobc7o4']);assert.equal(p.notes['lighthouse-120'],'Личная заметка');assert.equal(p.notes.unknown,undefined);
 assert.equal(filterSpawns(spawns,{mapId:'lighthouse',floor:'floor-1'}).length,31);
});
