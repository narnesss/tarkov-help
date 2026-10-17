import assert from 'node:assert/strict';
import {test} from 'node:test';
import {filterQuests} from '../../public/quest-model.mjs';
const progress={level:70,mode:'pve',faction:'any',quests:{}};
const task=(id,name,level=1,description='',extra={})=>({id,name,normalizedName:id,minPlayerLevel:level,objectives:[{description}],...extra});
const names=(tasks,options={})=>filterQuests(tasks,{progress,...options}).map(t=>t.name);

test('точное название «Тур» выше совпадения внутри целей «Бати» независимо от уровня',()=>{
 const tasks=[task('batya','Батя',1,'Выполните тур по локациям'),task('tour-long','Тур по городу',2),task('tour','Тур',60),task('another-tour','Большой тур',3),task('adventure','Натура',4)];
 assert.deepEqual(names(tasks,{query:'  ТУР  '}),['Тур','Тур по городу','Большой тур','Натура','Батя']);
 assert.deepEqual(names(tasks,{query:'тур',sort:'xp'}),['Тур','Тур по городу','Большой тур','Натура','Батя']);
});

test('ё, пробелы и несколько слов нормализуются, поиск по целям сохраняется',()=>{
 const tasks=[task('mixed','Путь на север',1,'Найдите ёлку'),task('tree','Северная ёлка',40),task('partial','Север',1,'Ищите склад'),task('other','Другое',1,'Ёлка на севере')];
 assert.deepEqual(names(tasks,{query:' СЕВЕР   ЕЛК '}),['Северная ёлка','Другое','Путь на север']);
 assert.deepEqual(names(tasks,{query:'несуществующий текст'}),[]);
});

test('выбранная сортировка остаётся внутри одинаковой релевантности и без поиска',()=>{
 const tasks=[task('b','Тур Б',20,'', {experience:100}),task('a','Тур А',30,'',{experience:500}),task('c','Тур В',10,'',{experience:200})];
 assert.deepEqual(names(tasks,{query:'тур'}),['Тур В','Тур Б','Тур А']);
 assert.deepEqual(names(tasks,{query:'тур',sort:'name'}),['Тур А','Тур Б','Тур В']);
 assert.deepEqual(names(tasks,{query:'тур',sort:'xp'}),['Тур А','Тур В','Тур Б']);
 assert.deepEqual(names(tasks),['Тур В','Тур Б','Тур А']);
 assert.deepEqual(names(tasks,{sort:'name'}),['Тур А','Тур Б','Тур В']);
 assert.deepEqual(names(tasks,{sort:'xp'}),['Тур А','Тур В','Тур Б']);
});

test('поиск совместим с фильтрами и не меняет задания или прогресс',()=>{
 const tasks=[task('a','Тур',10,'',{trader:{id:'trader'},map:{id:'map'},kappaRequired:true}),task('b','Тур 2',10,'',{trader:{id:'other'}})];
 const state={...progress,quests:{a:{status:'active',favorite:true,note:'сохранить'}}};
 const before=structuredClone({tasks,state});
 assert.deepEqual(names(tasks,{query:'тур',trader:'trader',map:'woods',maps:[{id:'map',normalizedName:'woods'}],status:'active',flag:'favorite',progress:state}),['Тур']);
 assert.deepEqual({tasks,state},before);
 assert.deepEqual(names(tasks,{query:"' OR 1=1 --"}),[]);
 assert.deepEqual(names(tasks,{query:'<img onerror=alert(1)>'}),[]);
});
