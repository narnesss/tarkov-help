import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {gunzipSync} from 'node:zlib';
import {applyQuestPatch} from '../../public/quest-patch-update.mjs';
import {applyCatalogUpdate} from '../../public/quest-catalog-update.mjs';

const read=file=>JSON.parse(file.endsWith('.gz')?gunzipSync(fs.readFileSync(file)):fs.readFileSync(file));
const patch=read('public/data/guide/quest-patch-1150.json'),prefix='event-20261016-';
const additions=read('database/content-updates/20261017-hidden-route-event.json');
const routes=read('public/data/page-routes.json'),navigation=read('public/data/navigation-routes.json');

test('Шесть событийных квестов доступны по стабильным ID без дублей в обоих каталогах',()=>{
 const catalog=read('public/data/guide/catalog-update.json.gz');
 for(const mode of ['pve','regular']){
  const source=read(`public/data/guide/quests-${mode}.json.gz`);
  const merged=applyQuestPatch(applyCatalogUpdate(source,catalog),patch);
  const tasks=merged.tasks.filter(t=>t.id.startsWith(prefix));
  assert.equal(tasks.length,6);assert.equal(new Set(merged.tasks.map(t=>t.id)).size,merged.tasks.length);
  assert.equal(tasks.reduce((count,t)=>count+t.objectives.length,0),12);
  for(const task of tasks){
   assert.match(task.name,/[А-ЯЁа-яё]/);assert.equal(task.idKind,'local-wiki-stable');assert.equal(task.gameId,null);
   assert.equal(task.catalogSection,'main');assert.equal(task.catalogKind,'event');
   assert.match(task.questContent.trader,/^(Терапевт|Механик)$/);assert.equal(task.questContent.briefing.kind,'summary');
   assert.equal(routes.quests[task.id].slug,task.normalizedName);assert.equal(navigation.quests[task.id].slug,task.normalizedName);
   assert.equal(task.modeCoverage[mode],'not-specified-in-user-archive');
   const update=additions.quests.find(q=>q.id===task.id);assert.deepEqual(update.expectedObjectiveIds,task.objectives.map(o=>o.id));
   for(const goal of task.objectives){assert.match(goal.description,/[А-ЯЁа-яё]/);assert.ok(goal.id.startsWith(task.id+'-goal-'));}
   for(const link of task.taskRequirements)assert.ok(tasks.some(t=>t.id===link.task));
   for(const frame of task.gallery){assert.ok(fs.existsSync('public'+frame.url));assert.match(frame.caption,/[А-ЯЁа-яё]/);for(const id of frame.objectiveIds)assert.ok(task.objectives.some(o=>o.id===id));}
  }
 }
});

test('Цепочка, стартовые карты, предметы наград и количества соответствуют сохранённым страницам',()=>{
 const tasks=patch.modes.pve.tasks.filter(t=>t.id.startsWith(prefix)),task=slug=>tasks.find(t=>t.normalizedName===slug);
 const bundle=read('public/data/guide/quests-pve.json.gz');const items=new Set([...Object.keys(bundle.items),...bundle.questItems.map(i=>i.id)]);
 for(const t of tasks){for(const timing of ['startRewards','finishRewards'])for(const r of t[timing].items)assert.ok(items.has(r.item),r.item);for(const goal of t.objectives)for(const ref of [...(goal.items||[]),...(goal.questItem?[goal.questItem]:[])]){assert.ok(items.has(ref),ref);assert.ok(routes.items[ref]);}}
 assert.deepEqual(task('doxed').taskRequirements,[{task:task('side-hustle').id,status:['active']}]);
 assert.equal(task('extending-the-operation').objectives[0].count,6);
 assert.equal(task('side-hustle').objectives[0].count,3);
 assert.equal(task('side-hustle').objectives.find(o=>o.type==='giveItem').count,3);
 assert.equal(task('side-hustle').objectives.filter(o=>o.optional).length,1);
 assert.equal(task('a-simple-task-in-a-difficult-place').startRewards.items[0].count,1);
 assert.equal(task('extending-the-operation').startRewards.items[0].count,3);
 assert.equal(task('information-trail').startRewards.items[0].count,1);
 const money=t=>t.finishRewards.items.find(r=>r.item==='5449016a4bdc2d6f028b456f')?.count;
 assert.equal(money(task('hidden-route')),250000);assert.equal(money(task('doxed')),275000);
 assert.deepEqual(task('extending-the-operation').finishRewards.skillLevelReward,[{skill:'Surgery',level:1}]);
 const visited=new Set(),visiting=new Set();const visit=t=>{assert.ok(!visiting.has(t.id),'Цикл зависимостей');if(visited.has(t.id))return;visiting.add(t.id);for(const r of t.taskRequirements)visit(tasks.find(p=>p.id===r.task));visiting.delete(t.id);visited.add(t.id);};tasks.forEach(visit);
});
