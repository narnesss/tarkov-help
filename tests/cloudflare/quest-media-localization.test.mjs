import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import {gunzipSync,gzipSync} from 'node:zlib';
import {localizeQuestMedia,localizeQuestMediaTask,mergeQuestMediaLocalization} from '../../public/quest-media-model.mjs';
import {questGalleryPlan} from '../../public/quest-view-model.mjs';
import {storyGallery} from '../../public/quest-extras.mjs';
import {json} from '../../public/guide-common.mjs';

const read = name => { const file=new URL(`../../public/data/guide/${name}.json`,import.meta.url); return JSON.parse(fs.existsSync(file)?fs.readFileSync(file,'utf8'):gunzipSync(fs.readFileSync(new URL(file.href+'.gz')))); };
const localization = read('media-localization');
const story = read('story');

test('версия gzip-таблицы переводов обходит прежний статический кэш и корректно распаковывается',async()=>{
  const original=globalThis.fetch,requested=[],url='data/guide/media-localization.json.gz?v=media-ru-20261017';
  globalThis.fetch=async target=>{requested.push(target);return target==='data/compressed.json'?Response.json({}):new Response(gzipSync(JSON.stringify({images:{test:{url:'assets/ru.webp'}}})));};
  try{assert.deepEqual(await json(url),{images:{test:{url:'assets/ru.webp'}}});assert.ok(requested.includes(url));}
  finally{globalThis.fetch=original;}
});

test('для всех ранее выявленных китайских иллюстраций существуют локальные замены',()=>{
  const audit=read('media-audit'),sources=new Set([...audit.images.map(frame=>frame.originalUrl),...audit.retainedOriginals.map(frame=>frame.url)]);
  assert.ok(sources.size>=315);
  for(const source of sources){
    const frame=localizeQuestMedia({url:source,fullUrl:source},localization);
    assert.notEqual(frame.url,source,source);
    assert.ok(fs.existsSync(new URL('../../public/'+frame.url,import.meta.url)),frame.url);
  }
});

test('старый набор CMS не скрывает новый файловый перевод; редакторская картинка приоритетна',()=>{
  const source='https://wiki.test/image.webp',bundled={images:{[source]:{url:'assets/translated.webp',annotationRu:'Перевод'}}};
  const old={images:{[source]:{annotationRu:'Прежняя подпись'}}};
  assert.equal(mergeQuestMediaLocalization(bundled,old).images[source].url,'assets/translated.webp');
  const custom={images:{[source]:{url:'assets/editor.webp'}}};
  assert.equal(mergeQuestMediaLocalization(bundled,custom).images[source].url,'assets/editor.webp');
  assert.equal(bundled.images[source].url,'assets/translated.webp');
});

test('неверные метаданные перевода не меняют ID иллюстрации и цели',()=>{
  const source='https://wiki.test/image.webp',frame={id:'stable',url:source,objectiveIds:['goal'],association:'objective'};
  const translated=localizeQuestMedia(frame,{images:{[source]:{url:'assets/ru.webp',id:'another',objectiveIds:['other'],association:'quest'}}});
  assert.equal(translated.id,'stable');assert.deepEqual(translated.objectiveIds,['goal']);assert.equal(translated.association,'objective');
  assert.deepEqual(localizeQuestMedia(frame,{images:{[source]:null}}),frame);
  assert.deepEqual(mergeQuestMediaLocalization({images:{[source]:{url:'assets/ru.webp'}}},{images:{[source]:null}}).images[source],{url:'assets/ru.webp'});
});

test('полная серверная карточка Тура использует прежние русские картинки во всех целях',()=>{
  const task=story.tasks.find(task=>task.normalizedName==='tour');
  const before=structuredClone(task),translated=localizeQuestMediaTask(task,localization);
  assert.deepEqual(task,before);
  assert.equal(translated.id,task.id);
  assert.deepEqual(translated.objectives.map(goal=>goal.id),task.objectives.map(goal=>goal.id));
  const plan=questGalleryPlan(translated);
  assert.equal(plan.frames.length,7);
  for(const frame of plan.frames){
    assert.ok(frame.url.startsWith('/assets/quest-media-ru/'),frame.url);
    assert.equal(frame.fullUrl,frame.url);
    assert.ok(frame.originalUrl.startsWith('https://www.eftarkov.com/'));
    assert.ok(frame.objectiveIds.length>0);
  }
});

test('новый импорт сюжетной галереи сохраняет прежние ID и привязки после подстановки перевода',()=>{
  for(const task of story.tasks){
    const before=storyGallery(task),after=storyGallery(localizeQuestMediaTask(task,localization));
    const identities=frames=>frames.map(frame=>({id:frame.id,objectiveIds:frame.objectiveIds}));
    assert.deepEqual(identities(after),identities(before),task.name);
  }
});

test('все готовые переводы существуют; повторная обработка сохраняет URL источника и связи',()=>{
  let edited=0;
  for(const [source,patch] of Object.entries(localization.images)){
    if(!patch.url)continue;
    assert.ok(fs.existsSync(new URL('../../public/'+patch.url,import.meta.url)),patch.url);
    const frame={id:'stable-media',url:source,fullUrl:source,objectiveIds:['stable-goal'],sourceUrl:'https://wiki.test/source'};
    const translated=localizeQuestMedia(frame,localization);
    assert.equal(translated.id,frame.id);
    assert.deepEqual(translated.objectiveIds,frame.objectiveIds);
    assert.equal(translated.originalUrl,source);
    assert.equal(translated.fullUrl,patch.url.replace(/^(?:\.\/)?assets\//,'/assets/'));
    assert.deepEqual(localizeQuestMedia(translated,localization),translated);
    edited++;
  }
  assert.ok(edited>=313);
});

test('оригинал с параметром кэша и все варианты галереи получают тот же перевод',()=>{
  const source=Object.keys(localization.images).find(url=>localization.images[url].url);
  const frame={url:source+'?version=2',objectiveIds:['goal']};
  const task={id:'same-quest',gallery:[frame],media:{gallery:[frame]},questContent:{gallery:[frame]},objectives:[{id:'goal',media:[frame]}]};
  const translated=localizeQuestMediaTask(task,localization),expected=localization.images[source].url.replace(/^(?:\.\/)?assets\//,'/assets/');
  for(const result of [translated.gallery[0],translated.media.gallery[0],translated.questContent.gallery[0],translated.objectives[0].media[0]]){
    assert.equal(result.url,expected);assert.equal(result.fullUrl,expected);assert.equal(result.originalUrl,frame.url);
  }
  assert.deepEqual(localizeQuestMedia({url:'https://elsewhere.test/original.webp'},localization),{url:'https://elsewhere.test/original.webp'});
});

// Вложенные адреса /quests/... не должны менять адрес переведённого файла.
test('переведённые изображения доступны с вложенных страниц квестов',()=>{
  const source='https://wiki.test/original.webp';
  for(const replacement of ['assets/ru.webp','./assets/ru.webp','/assets/ru.webp']){
    const result=localizeQuestMedia({url:source},{images:{[source]:{url:replacement}}});
    for(const page of ['/quests/tour','/quests/batya','/quests/tour/']){
      assert.equal(new URL(result.url,'https://site.test'+page).href,'https://site.test/assets/ru.webp');
      assert.equal(result.fullUrl,result.url);
    }
  }
  const custom='https://editor.test/custom.webp';
  assert.equal(localizeQuestMedia({url:source},{images:{[source]:{url:custom}}}).url,custom);
});

test('Тур и Батя сохраняют все изображения и связи после перевода',()=>{
  for(const [slug,count] of [['tour',7],['batya',34]]){
    const task=story.tasks.find(task=>task.normalizedName===slug);
    const before=questGalleryPlan(task),after=questGalleryPlan(localizeQuestMediaTask(task,localization));
    assert.equal(after.frames.length,count);
    assert.deepEqual(after.frames.map(frame=>frame.objectiveIds),before.frames.map(frame=>frame.objectiveIds));
    for(const frame of after.frames)assert.ok(!frame.url.startsWith('assets/'),frame.url);
  }
});
