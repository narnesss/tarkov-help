import test from 'node:test';
import assert from 'node:assert/strict';
import {consentError,configureLegal,collectLegal} from '../../public/legal-consent.mjs';
test('каждое согласие требует собственного булева подтверждения',()=>{
  const valid={terms:true,privacy:true,personal_data:true};assert.equal(consentError(valid),'');
  for(const field of Object.keys(valid))for(const value of [false,null,undefined,'true',1,[]])assert.ok(consentError({...valid,[field]:value}));
});
test('версия приходит от сервера, отсутствие метаданных не считается согласием',()=>{
  assert.throws(()=>collectLegal(null),/Дождитесь/);
  assert.throws(()=>configureLegal({required:true,version:'bad'}));
  assert.throws(()=>configureLegal({required:true,version:['a'.repeat(64)]}));
  configureLegal({required:true,version:'a'.repeat(64)});assert.throws(()=>collectLegal(null),/Примите/);
  configureLegal({required:false,version:'a'.repeat(64)});assert.deepEqual(collectLegal(null),{});
});
