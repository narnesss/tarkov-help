import assert from 'node:assert/strict';
import {test} from 'node:test';
import {sanitizeQuestProgress} from '../../public/quest-model.mjs';
import {contentResponse,contentViewResponse} from '../../public/content-client.mjs';

test('частичная карточка сохраняет отметки, ветку и цели остальных квестов',()=>{
 const raw={version:1,mode:'pve',quests:{selected:{status:'active',objectives:['goal','archived-goal']},other:{status:'complete',objectives:['unloaded-goal'],storyBranch:'unloaded-branch',favorite:true,note:'Мой прогресс'}}};
 const state=sanitizeQuestProgress(raw,[{id:'selected',objectives:[{id:'goal'}]}],{partial:true});
 assert.deepEqual(state.quests.other,raw.quests.other);assert.deepEqual(state.quests.selected.objectives,['goal','archived-goal']);
 state.quests.selected.status='complete';assert.deepEqual(state.quests.other,raw.quests.other);
 assert.equal(sanitizeQuestProgress(raw,[{id:'selected',objectives:[{id:'goal'}]}]).quests.other,undefined);
});

test('сбой backend не вызывает запрос frontend JSON даже для старого gzip URL',async()=>{
 const requests=[];const request=async(url,options)=>{requests.push(url);assert.equal(options.credentials,'omit');return new Response('',{status:503});};
 await assert.rejects(contentResponse('data/guide/quests-pve.json.gz?v=old',undefined,request),/загрузить данные с сервера/);
 assert.deepEqual(requests,['/api/content/data/guide/quests-pve.json']);
});

test('карточка босса запрашивается по ID и режиму отдельным ответом backend',async()=>{
 const requests=[];const response=await contentViewResponse('data/guide/bosses.json',{view:'detail',id:'birdeye',mode:'pve'},undefined,async url=>{requests.push(url);return Response.json({profiles:[{id:'followerBirdEye'}]});});
 assert.equal((await response.json()).profiles.length,1);assert.deepEqual(requests,['/api/content/data/guide/bosses.json?view=detail&id=birdeye&mode=pve']);
});
