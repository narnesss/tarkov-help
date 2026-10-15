import {test} from 'node:test';
import assert from 'node:assert/strict';
import {accountSummary} from '../../public/account-model.mjs';

test('сводка исключает старые ID, дубликаты и неподтверждённые отметки',()=>{
  const catalog={tasks:[{id:'done'},{id:'active'},{id:'new'},{id:'done'}],hideout:[{id:'s1',name:'Станция',levels:[{id:'l1',level:1},{id:'l2',level:2}]}],achievements:[{id:'a1'},{id:'a2'}]};
  const state={level:21,quests:{done:{status:'complete'},active:{status:'active'},removed:{status:'complete'}}};
  const result=accountSummary(catalog,state,{l1:true,l2:'true',removed:true},['a1','a1','removed']);
  assert.equal(result.level,21);assert.equal(result.quests.done,1);assert.equal(result.quests.total,3);assert.equal(result.quests.active[0].id,'active');
  assert.equal(result.hideout.done,1);assert.equal(result.hideout.total,2);assert.equal(result.hideout.unlocked[0].stationName,'Станция');
  assert.equal(result.achievements.done,1);assert.equal(result.achievements.total,2);
});
test('новый профиль и пустой каталог не ломают личный кабинет',()=>{
  const result=accountSummary({},null,null,null);
  assert.equal(result.level,1);for(const key of ['quests','hideout','achievements']){assert.equal(result[key].done,0);assert.equal(result[key].total,0);}
});
test('сводки разных режимов используют только переданный профиль',()=>{
  const catalog={tasks:[{id:'quest'}],hideout:[],achievements:[]};
  const pve=accountSummary(catalog,{quests:{quest:{status:'complete'}}});
  const pvp=accountSummary(catalog,{quests:{quest:{status:'active'}}});
  assert.equal(pve.quests.done,1);assert.equal(pvp.quests.done,0);assert.equal(pvp.quests.active.length,1);
});
