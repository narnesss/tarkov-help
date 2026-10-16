import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import {gunzipSync} from 'node:zlib';
import {questMapKey,questMapForObjective,questMapFocusIndexes} from '../../public/quest-map-model.mjs';
import {publicLinkURL} from '../../public/public-links.mjs';
const patch=JSON.parse(fs.readFileSync(new URL('../../database/migrations/data/quest-guides-20261016.json',import.meta.url)));
const index=JSON.parse(gunzipSync(fs.readFileSync(new URL('../../public/data/guide/quest-map-index.json.gz',import.meta.url))));
test('схемы одной локации различаются и выбираются по существующей цели',()=>{
 const old={id:'icebreaker',renderer:'source',sourceKey:'old',points:[{objectiveId:'under'}]},next={id:'icebreaker',renderer:'source',sourceKey:'new',points:[{objectiveId:'storage'}]};
 assert.notEqual(questMapKey(old),questMapKey(next));assert.equal(questMapForObjective([old,next],'icebreaker','storage'),next);assert.equal(questMapForObjective([old,next],'icebreaker','under'),old);
 assert.deepEqual(questMapFocusIndexes({points:[{objectiveIds:['a','b']}]},'b'),[0]);
});
test('дополнены только проверенные цели; все локальные изображения и схемы существуют',()=>{
 assert.equal(Object.keys(patch.quests).length,21);
 for(const [id,quest] of Object.entries(patch.quests)){
  const valid=new Set(quest.expectedObjectiveIds);
  for(const frame of quest.gallery){for(const goal of frame.objectiveIds)assert.ok(valid.has(goal),id+': '+goal);for(const field of ['url','fullUrl'])assert.ok(fs.existsSync(new URL('../../public/'+frame[field],import.meta.url)),frame[field]);}
  for(const map of quest.maps){const asset=patch.sourceMaps[map.sourceKey]||index.sourceMaps[map.sourceKey];assert.ok(asset?.width>0&&asset.height>0,map.sourceKey);assert.ok(fs.existsSync(new URL('../../public/'+asset.src,import.meta.url)),asset.src);
   for(const point of map.points){assert.ok(point.x>=0&&point.x<=100&&point.y>=0&&point.y<=100);for(const goal of point.objectiveIds||[])assert.ok(valid.has(goal),goal);}
   for(const mode of ['pve','regular']){assert.ok(index.modes[mode][id].some(row=>row.sourceKey===map.sourceKey));assert.deepEqual(index.sourceQuests[id][map.sourceKey],map.points);}
  }
 }
});
test('для новых источников разрешены только HTTPS-страницы прохождений',()=>{
 const url='https://www.tarkovhead.com/en/quest/regular/unanswered-calls';assert.equal(publicLinkURL(url),url);
 for(const bad of ['javascript:alert(1)','https://www.tarkovhead.com.evil.test/en/quest/regular/x','https://www.tarkovhead.com/admin','http://www.tarkovhead.com/en/quest/regular/x','https://user:pass@www.tarkovhead.com/en/quest/regular/x'])assert.equal(publicLinkURL(bad),'');
});

import {questGalleryPlan} from '../../public/quest-view-model.mjs';
test('старые места V3 сохраняются в архиве и не подменяют актуальные фотографии',()=>{const old={url:'https://example.test/old.jpg',caption:'Старое место'},fresh={url:'assets/new.webp',caption:'Новое место',objectiveIds:['goal']};const task={objectives:[{id:'goal'}],gallery:[old,fresh],galleryArchiveRules:[{urls:[old.url],reason:'До переработки'}]},plan=questGalleryPlan(task);assert.equal(plan.archived.length,1);assert.equal(plan.archived[0].url,old.url);assert.deepEqual(plan.byObjective.get('goal').map(frame=>frame.url),[fresh.url]);assert.equal(task.gallery.length,2);});
