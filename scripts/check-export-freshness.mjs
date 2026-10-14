import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {root,sourceFingerprint} from './build-pages.mjs';

// Подтверждаем, что случайная правка Blade не проходит проверку свежести экспорта.
const manifest=JSON.parse(fs.readFileSync(path.join(root,'public/data/view-export.json'),'utf8'));
assert.equal(manifest.source_hash,sourceFingerprint());
const filename=path.join(root,'resources/views/layouts/site.blade.php');
const original=fs.readFileSync(filename);
try {
 fs.appendFileSync(filename,'\n{{-- Проверка устаревшего экспорта --}}\n');
 assert.notEqual(manifest.source_hash,sourceFingerprint());
 const result=spawnSync(process.execPath,['scripts/build-pages.mjs'],{cwd:root,encoding:'utf8',env:{...process.env,PHP_BINARY:path.join(root,'missing-php-for-test')}});
 assert.notEqual(result.status,0);
 assert.match(result.stderr,/export is stale/);
}finally {fs.writeFileSync(filename,original);}
assert.equal(manifest.source_hash,sourceFingerprint());
console.log('PASS: committed export matches Blade; modified Blade invalidates it.');
