import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {root} from './build-pages.mjs';

// В API-пакет попадают только явно разрешённые исходники, без карт, dist, БД и .env.
const output = path.resolve(root, 'build/alwaysdata-api');
if (!output.startsWith(root + path.sep) || path.dirname(output) !== path.join(root, 'build')) throw Error('Unsafe package path');
if (fs.existsSync(output)) fs.rmSync(output, {recursive: true});
const copy = name => {
  const source = path.join(root, name), destination = path.join(output, name);
  fs.mkdirSync(path.dirname(destination), {recursive: true});
  fs.cpSync(source, destination, {recursive: true});
};
for (const name of ['app', 'config', 'routes', 'src', 'resources/views', 'database/migrations',
  'bootstrap/app.php', 'bootstrap/providers.php', 'public/index.php', 'public/.htaccess',
  'artisan', 'composer.json', 'composer.lock', 'deployment/alwaysdata']) copy(name);
for (const name of ['bootstrap/cache', 'storage/app/private', 'storage/framework/cache/data',
  'storage/framework/cache/locks', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs']) {
  fs.mkdirSync(path.join(output, name), {recursive: true});
}
const archive = path.join(root, 'build/alwaysdata-api.tar.gz');
const result = spawnSync('tar', ['-czf', archive, '-C', output, '.'], {encoding: 'utf8'});
if (result.status !== 0) throw Error(result.stderr || 'tar is required to package the API');
console.log(`Пакет API без vendor и личных данных: ${archive} (${fs.statSync(archive).size} bytes)`);
