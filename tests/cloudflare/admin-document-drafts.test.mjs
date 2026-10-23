import test from 'node:test';
import assert from 'node:assert/strict';
import {documentPoints,documentChange,updateDocumentDraft,discardDocumentDraft,rebaseDocumentDrafts} from '../../public/admin-document-drafts.mjs';

const fixture=()=>({floors:['floor-1','floor-2'],points:[
  {kind:'document',pointId:1,positionId:11,name:'Документ «0»',floor:'floor-1',x:0,y:100},
  {kind:'document',pointId:1,positionId:12,name:'Документ «0»',floor:'floor-1',x:50,y:25},
  {kind:'exit',pointId:2,positionId:null,name:'Выход',floor:'floor-1',x:100,y:0},
]});

test('несколько черновиков переживают выбор, а отмена одной точки сохраняет остальные',()=>{
  const saved=documentPoints(fixture()),points=structuredClone(saved),pending=new Map();
  updateDocumentDraft(points,pending,points[0],{x:10});
  updateDocumentDraft(points,pending,points[2],{y:20});
  assert.equal(pending.size,2);
  const selected=discardDocumentDraft(points,saved,pending,points[0]);
  assert.deepEqual(selected,saved[0]);assert.equal(pending.size,1);assert.equal(points[2].y,20);
  assert.deepEqual(documentChange(points[2]),pending.get(points[2].id));
});

test('общие поля альтернативных позиций меняются и отменяются вместе с независимыми координатами',()=>{
  const saved=documentPoints(fixture()),points=structuredClone(saved),pending=new Map();
  updateDocumentDraft(points,pending,points[0],{x:30});
  updateDocumentDraft(points,pending,points[1],{name:'0',description:'Описание\nвторая строка',floor:'floor-2',archived:true});
  assert.equal(pending.size,2);assert.equal(points[0].name,'0');assert.equal(points[0].x,30);assert.equal(points[1].x,50);
  assert.equal(points[0].description,'Описание\nвторая строка');
  discardDocumentDraft(points,saved,pending,points[1]);assert.deepEqual(points,saved);assert.equal(pending.size,0);
});

test('отмена новой точки убирает только её черновик',()=>{
  const saved=documentPoints(fixture()),points=structuredClone(saved),pending=new Map();
  const created={id:'editor-new',kind:'document',name:'Новая',documentId:8,floor:'floor-1',x:0,y:0};points.push(created);
  updateDocumentDraft(points,pending,points[2],{x:10});updateDocumentDraft(points,pending,created,{});
  assert.equal(pending.get(created.id).documentId,8);
  assert.equal(discardDocumentDraft(points,saved,pending,created),null);assert.equal(points.length,3);assert.equal(pending.size,1);
});

test('лимит 100 позиций проверяется до мутации, включая альтернативы',()=>{
  const points=documentPoints(fixture()),pending=new Map(Array.from({length:99},(_,i)=>['other-'+i,{}]));
  const before=structuredClone(points);
  assert.throws(()=>updateDocumentDraft(points,pending,points[0],{name:'Новое'}),/100/);
  assert.deepEqual(points,before);assert.equal(pending.size,99);
  updateDocumentDraft(points,pending,points[2],{x:0});assert.equal(pending.size,100);
});

test('409 объединяет локальные правки с новой версией, сохраняя чужие точки',()=>{
  const snapshot=fixture(),points=documentPoints(snapshot),pending=new Map();
  updateDocumentDraft(points,pending,points[0],{x:12});snapshot.points[2].name='Название другого редактора';
  const result=rebaseDocumentDrafts(snapshot,points,pending);
  assert.equal(result.points[0].x,12);assert.equal(result.points[2].name,'Название другого редактора');assert.equal(result.pending.size,1);
  assert.equal(snapshot.points[0].x,0);
});

test('потерянный успешный ответ новой точки не создаёт дубль при повторе',()=>{
  const snapshot=fixture(),points=documentPoints(snapshot),pending=new Map();
  const created={id:'editor-new',kind:'exit',name:'Выход 0',floor:'floor-2',x:0,y:100};points.push(created);updateDocumentDraft(points,pending,created,{});
  snapshot.points.push({...created,id:undefined,pointId:25,positionId:null,clientId:created.id});
  const result=rebaseDocumentDrafts(snapshot,points,pending),change=[...result.pending.values()][0];
  assert.equal(result.points.length,4);assert.equal(change.pointId,25);assert.equal(result.ids.get('editor-new'),'exit:25:0');
});

test('удаление точки или этажа на сервере сохраняет все исходные черновики при отказе объединения',()=>{
  const snapshot=fixture(),points=documentPoints(snapshot),pending=new Map();updateDocumentDraft(points,pending,points[0],{x:30});
  const before=structuredClone(points),changes=structuredClone([...pending]);
  assert.throws(()=>rebaseDocumentDrafts({...snapshot,points:[]},points,pending),/удалена/);
  assert.throws(()=>rebaseDocumentDrafts({...snapshot,floors:[]},points,pending),/этажей/);
  assert.deepEqual(points,before);assert.deepEqual([...pending],changes);
});
