import test from 'node:test';
import assert from 'node:assert/strict';
import {discardPointDraft} from '../../public/admin-map-drafts.mjs';

test('Отмена выбранной точки восстанавливает все поля и сохраняет остальные правки', () => {
  const saved = [{id:'one',name:'Точка «1»',position:{x:0,y:0,z:0},itemIds:['key'],description:'Строка\n<текст>'},
    {id:'two',name:'Выход',position:{x:2,y:3,z:4}}];
  const points = structuredClone(saved);
  Object.assign(points[0],{name:'Правка',floorOverride:'upper',cmsArchived:true,editorPosition:true});
  points[0].position.x = 15;
  points[1].name = 'Оставить эту правку';
  const other = {id:'two',name:points[1].name};
  const pending = new Map([['one',{id:'one'}],['two',other]]);
  const selected = discardPointDraft(points,saved,pending,'one');
  assert.deepEqual(selected,saved[0]);
  assert.equal(selected,points[0]);
  assert.equal(points[1].name,'Оставить эту правку');
  assert.deepEqual([...pending.keys()],['two']);
  assert.equal(pending.get('two'),other);
  selected.position.x = 90;
  selected.itemIds.push('next');
  assert.equal(saved[0].position.x,0);
  assert.deepEqual(saved[0].itemIds,['key']);
});

test('Отмена новой точки убирает только её черновик и допускает повторную отмену', () => {
  const saved = [{id:'existing',position:{x:0,y:0,z:0}}];
  const points = [...structuredClone(saved),{id:'new',isNew:true},{id:'another',isNew:true}];
  const pending = new Map([['new',{id:'new'}],['another',{id:'another'}]]);
  assert.equal(discardPointDraft(points,saved,pending,'new'),null);
  assert.deepEqual(points.map(p=>p.id),['existing','another']);
  assert.deepEqual([...pending.keys()],['another']);
  assert.equal(discardPointDraft(points,saved,pending,'new'),null);
  assert.equal(points.length,2);
});

test('После объединения конфликта отмена возвращает актуальный серверный снимок', () => {
  const saved = [{id:'one',name:'Новая серверная версия',floorOverride:'base',position:{x:1,y:2,z:3}}];
  const points = [{...saved[0],name:'Моя правка',cmsArchived:true}];
  const pending = new Map([['one',{id:'one'}]]);
  assert.deepEqual(discardPointDraft(points,saved,pending,'one'),saved[0]);
  assert.equal(pending.size,0);
});
