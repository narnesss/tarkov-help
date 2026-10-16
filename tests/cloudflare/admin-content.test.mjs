import test from 'node:test';
import assert from 'node:assert/strict';
import {contentError,safeContentUrl} from '../../public/admin-content-validation.mjs';

test('обычный текст, длина и HTML проверяются до отправки формы',()=>{
  assert.equal(contentError('  Нормальное имя  ',{required:true,max:15}),'');
  assert.ok(contentError('   ',{required:true}));
  assert.ok(contentError('123456',{max:5}));
  for(const value of ['<script>alert(1)</script>','</textarea><img onerror=x>','bad\0text'])assert.ok(contentError(value));
  assert.equal(contentError("О'Брайен; DROP TABLE users --\nОписание"),'');
  assert.equal(contentError('<svg onload=x>',{search:true}),'');
});
test('ссылки разрешают HTTP(S) и относительные пути, отклоняют исполняемые схемы',()=>{
  for(const value of ['https://example.test/a?q=2','http://example.test/a','/maps/a.svg','images/a.png'])assert.ok(safeContentUrl(value),value);
  for(const value of ['javascript:alert(1)','data:text/html,x','//evil.test/a','https://a.test\\@evil.test','https://user:pass@example.test','file:///a','https://a.test/<svg>'])assert.ok(!safeContentUrl(value),value);
  assert.ok(contentError('javascript:alert(1)',{url:true}));
});
