import test from 'node:test';
import assert from 'node:assert/strict';
import {playerErrors} from '../../public/admin-user-validation.mjs';
const valid={username:'test_player',display_name:'Тестовый игрок',avatar_url:'',email:'',password:'Cedar!Kite9_Moon'};

test('форма Orchid отклоняет SQL/HTML, опасные аватары и слабые пароли до отправки',()=>{
  assert.deepEqual(playerErrors(valid,{creating:true}),{});
  assert.ok(playerErrors({...valid,username:"probe' OR '1'='1"}).username);
  assert.ok(playerErrors({...valid,display_name:'<img src=x onerror=alert(1)>'}).display_name);
  assert.ok(playerErrors({...valid,display_name:'\u0000Имя'}).display_name);
  assert.ok(playerErrors({...valid,email:'not-an-email'}).email);
  for(const avatar_url of ['javascript:alert(1)','data:image/svg+xml,x','https://cdn.discordapp.com.evil.test/x','https://cdn.discordapp.com@evil.test/x','https://cdn.discordapp.com:443/x','https://cdn.discordapp.com/x" onerror="alert(1)']){
    assert.ok(playerErrors({...valid,avatar_url}).avatar_url,avatar_url);
  }
  for(const password of ['lowercaseonly9!','Qwerty!Kite99','Ё'.repeat(36)+'Aa1!',''])assert.ok(playerErrors({...valid,password},{creating:true}).password);
  assert.deepEqual(playerErrors({...valid,password:''}),{});
  assert.deepEqual(playerErrors({...valid,avatar_url:'https://lh3.googleusercontent.com/avatar'}),{});
});
