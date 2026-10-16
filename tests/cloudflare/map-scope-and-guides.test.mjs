import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import zlib from 'node:zlib';
import {scopedRaidPoints} from '../../public/raid-scope.mjs';
import {questMapForLocation,questMapFocusIndexes,questMapPlan} from '../../public/quest-map-model.mjs';
import {applyQuestContent} from '../../public/quest-content-model.mjs';
const data=name=>JSON.parse(zlib.gunzipSync(fs.readFileSync(new URL('../../public/data/'+name+'.json.gz',import.meta.url))));

test('карта отклоняет чужой набор, режим и архивные точки; переход на другую карту допустим',()=>{
 assert.throws(()=>scopedRaidPoints({mapId:'woods',pois:[]},'ground-zero','pve'),/другой карте/);
 const points=[{id:'valid',gameMode:'pve'},{id:'foreign',sourceMapId:'woods'},{id:'archived',cmsArchived:true},{id:'other-mode',gameMode:'regular'},
  {id:'transit',targetMapId:'woods',gameMode:'pve'}];
 assert.deepEqual(scopedRaidPoints({mapId:'ground-zero',pois:points},'ground-zero','pve').map(p=>p.id),['valid','transit']);
});

test('все 24 набора карт принадлежат своей локации и режиму; ошибочные лесные выходы Эпицентра архивированы',()=>{
 const names=fs.readdirSync(new URL('../../public/data/raid/',import.meta.url)).filter(name=>name.endsWith('.json.gz'));
 let checked=0;for(const mode of ['regular','pve'])for(const name of names){const path='raid/'+(mode==='pve'?'pve/':'')+name.replace('.json.gz','');
  if(!fs.existsSync(new URL('../../public/data/'+path+'.json.gz',import.meta.url)))continue;
  const payload=data(path);if(!payload.pois)continue;const map=name.replace('.json.gz','');assert.equal(payload.mapId,map);
  const scoped=scopedRaidPoints(payload,map,mode),kept=new Set(scoped.map(p=>p.id));
  for(const p of payload.pois.filter(p=>!p.cmsArchived&&!kept.has(p.id)))assert.ok(p.hiddenByDefault&&['supplemental','unverified-coordinates'].includes(p.sourceStatus),path+': '+p.id);checked++;
  if(map==='ground-zero')for(const source of ['UN Roadblock','East Gate'])assert.ok(payload.pois.filter(p=>p.sourceName===source).every(p=>p.cmsArchived));
 }
 assert.equal(checked,24);
});

test('все видимые контейнеры, предметы и двери лежат в пределах авторской карты с допуском 10 м',()=>{
 const maps=JSON.parse(fs.readFileSync(new URL('../../public/data/raid/maps.json',import.meta.url)));
 for(const mode of ['regular','pve'])for(const map of maps){
  const path='raid/'+(mode==='pve'?'pve/':'')+map.id;if(!fs.existsSync(new URL('../../public/data/'+path+'.json.gz',import.meta.url)))continue;
  const payload=data(path),[[x1,z1],[x2,z2]]=map.bounds;
  for(const point of scopedRaidPoints(payload,map.id,mode).filter(p=>!p.hiddenByDefault&&['loot','loose-loot','locked-door'].includes(p.kind))){
   const {x,z}=point.position,dx=Math.max(Math.min(x1,x2)-x,x-Math.max(x1,x2),0),dz=Math.max(Math.min(z1,z2)-z,z-Math.max(z1,z2),0);
   assert.ok(Math.hypot(dx,dz)<=10,path+': '+point.id);
  }
 }
 for(const mode of ['regular','pve']){const ground=data('raid/'+(mode==='pve'?'pve/':'')+'ground-zero');
  for(const id of ['loot-7','api-loose-loot-42ed7b256c70548b'])assert.ok(ground.pois.find(p=>p.id===id).hiddenByDefault);
 }
});

test('неподтверждённый фильтр с одинаковыми координатами разных карт не публикуется',()=>{
 for(const mode of ['regular','pve'])for(const map of ['customs','interchange','woods']){
  const payload=data('raid/'+(mode==='pve'?'pve/':'')+map);
  assert.ok(!scopedRaidPoints(payload,map,mode).some(p=>p.id==='api-loose-loot-e13887e7cf615be8'));
 }
});

test('12 обновлённых квестов имеют локальные иллюстрации и проверенные метки без замены целей',()=>{
 const update=JSON.parse(fs.readFileSync(new URL('../../database/content-updates/20261016-quest-guides.json',import.meta.url)));
 const index=data('guide/quest-map-index'),routes=JSON.parse(fs.readFileSync(new URL('../../public/data/page-routes.json',import.meta.url)));assert.equal(update.quests.length,12);
 for(const quest of update.quests){assert.ok(quest.walkthrough.paragraphs.length>=2);assert.ok(quest.gallery.length);
  for(const image of quest.gallery){assert.ok(fs.existsSync(new URL('../../public/'+image.url,import.meta.url)));for(const id of image.objectiveIds)assert.ok(quest.expectedObjectiveIds.includes(id));}
  for(const mode of quest.modes){const plans=questMapPlan({id:quest.id},mode,index).filter(m=>m.renderer==='source'&&update.datasets['guide/quest-map-index.json'].sourceMaps[m.sourceKey]);assert.ok(plans.length);
   for(const map of plans){assert.ok(index.sourceMaps[map.sourceKey]);assert.ok(routes.sourceMaps[map.sourceKey],'Схема должна иметь экспортируемую страницу');for(const point of map.points){assert.ok(point.x>0&&point.x<100&&point.y>0&&point.y<100);assert.ok(quest.expectedObjectiveIds.includes(point.objectiveId));}
    assert.ok(questMapForLocation(plans,map.locationId,map.points[0].objectiveId));
   }
  }
 }
 const quest=update.quests.find(q=>q.id==='patch-1150-number-temporarily-unavailable'),plan=index.modes.pve[quest.id].find(m=>m.renderer==='source'&&update.datasets['guide/quest-map-index.json'].sourceMaps[m.sourceKey]);
 assert.equal(plan.points.length,1);for(const id of quest.expectedObjectiveIds)assert.deepEqual(questMapFocusIndexes(plan,id),[0]);
});

test('инструкция не подменяет тип, ID, количество и предметы существующей цели',()=>{
 const task={id:'q',objectives:[{id:'goal',type:'findItem',count:3,items:['stable-item']}],finishRewards:{items:['reward']}};
 const result=applyQuestContent(task,{modes:{pve:{q:'guide'}},quests:{guide:{objectiveGuides:{goal:{id:'changed',type:'shoot',count:99,description:'Точное место',maps:['ground-zero']}}}}},'pve');
 assert.deepEqual(result.objectives[0],{...task.objectives[0],description:'Точное место',maps:['ground-zero']});assert.deepEqual(result.finishRewards,task.finishRewards);
});
