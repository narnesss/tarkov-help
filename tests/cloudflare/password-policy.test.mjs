import test from 'node:test';
import assert from 'node:assert/strict';
import {passwordError} from '../../public/password-policy.mjs';
test('новые пароли требуют длину и все типы символов, запрещают очевидные варианты',()=>{
  for(const weak of ['Short!7Aa','lowercase!only9','UPPERCASE!ONLY9','NoDigits!Here','NoSymbolsHere8','Password!Long8','P@ssw0rd!Long8','Fence!123456x','Fence!654321x','Fence!01011990','Fence!1990-01-01','Fence!AAAAAA8','Fence!abcdef8','Fence!\0Unique8'])assert.ok(passwordError(weak),weak);
  assert.ok(passwordError('User_Name!Kite8','user_name'));
  assert.equal(passwordError('Cedar!Kite9_Moon'),null);
  assert.equal(passwordError('Birch#Fawn8_River'),null);
  assert.ok(passwordError('Aa1!'+ 'é'.repeat(35)));
});
