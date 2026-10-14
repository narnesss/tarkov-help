import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,rmSync,writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
const root=new URL('../../',import.meta.url).pathname,storage=mkdtempSync(path.join(tmpdir(),'eft-php-entry-'));
const php=process.env.PHP_BINARY||'php';
writeFileSync(path.join(storage,'wiki.sqlite'),'');
const setup=spawnSync(php,['artisan','migrate','--force'],{cwd:root,encoding:'utf8',env:{...process.env,APP_STORAGE:storage,APP_KEY:'testing-only-key-for-entrypoint-32-bytes',APP_URL:'https://test.example'}});assert.equal(setup.status,0,setup.stderr);
const invoke=(uri,method='GET')=>{const result=spawnSync(php,['public/index.php'],{cwd:root,encoding:'utf8',env:{...process.env,APP_STORAGE:storage,APP_KEY:'testing-only-key-for-entrypoint-32-bytes',APP_URL:'https://test.example',REQUEST_URI:uri,REQUEST_METHOD:method}});assert.equal(result.status,0,result.stderr);return result.stdout;};
try {
 for(const route of ['/','/raid.html','/quests','/quest','/quest.html?quest=69d38381cea4b428690ea1d9','/reference/','/boss?boss=partisan','/boss.html']){const html=invoke(route);assert.ok(html.startsWith('<!doctype html>'));assert.ok(html.includes('name="wiki-runtime" content="php"'));assert.ok(html.includes('id="profile-button"'));}
 assert.equal(invoke('/','HEAD'),'');assert.ok(JSON.parse(invoke('/unknown')).error.includes('не найдена'));
 const session=JSON.parse(invoke('/api/session'));assert.equal(session.profile.authenticated,false);assert.ok(session.csrf);
 assert.ok(JSON.parse(invoke('/api/state')).error.includes('Сессия истекла'));
 for(const uri of ['/api/prices?mode=regular','/api/prices?mode=pve','/api/prices/daily'])assert.equal(JSON.parse(invoke(uri)).code,'prices_disabled');
 console.log('PASS: actual PHP front controller, page routes, HEAD, anonymous session, unauthorized state and missing route through CLI SAPI.');
}finally{rmSync(storage,{recursive:true,force:true});}
