import fs from 'node:fs';
import path from 'node:path';
import {gzipSync, gunzipSync} from 'node:zlib';
import {root} from './build-pages.mjs';
import {enrichQuests, localizeQuestMediaTask} from '../public/quest-extras.mjs';

// Переносим уже проверенные данные и обогащённые квесты, сохраняя все ID.
export function prepareContentSeed() {
  const source = path.join(root, 'public/data'), output = path.join(root, 'build/content-seed');
  const datasets = {};
  const excluded = new Set(['compressed.json', 'view-export.json', 'site-render-routes.json', 'page-routes.json', 'navigation-routes.json']);
  function walk(dir) {
    for (const entry of fs.readdirSync(dir, {withFileTypes: true})) {
      const file = path.join(dir, entry.name);
      if (entry.isDirectory()) { walk(file); continue; }
      if (!/\.json(?:\.gz)?$/.test(entry.name)) continue;
      const key = path.relative(source, file).replaceAll('\\', '/').replace(/\.gz$/, '');
      if (excluded.has(key)) continue;
      const bytes = fs.readFileSync(file);
      datasets[key] = JSON.parse((file.endsWith('.gz') ? gunzipSync(bytes) : bytes).toString('utf8'));
    }
  }
  walk(source);
  const names = {media:'quest-media', story:'story', conditions:'quest-conditions', patches:'objective-patches',
    wiki:'wiki-quests', gunsmith:'gunsmith', catalog:'catalog-update', questMaps:'quest-map-index',
    choices:'quest-choices', rewardSupport:'quest-reward-support', questPatch:'quest-patch-1150', ticketMap:'ticket-map', content:'quest-content'};
  const extras = Object.fromEntries(Object.entries(names).map(([key, name]) => [key, datasets[`guide/${name}.json`]]));
  const localization=datasets['guide/media-localization.json'];
  for(const [id,task] of Object.entries(extras.media?.quests||{}))extras.media.quests[id]=localizeQuestMediaTask(task,localization);
  extras.story.tasks=extras.story.tasks.map(task=>localizeQuestMediaTask(task,localization));
  const quests = {};
  for (const mode of ['pve', 'regular']) {
    const key = `guide/quests-${mode}.json`, bundle = enrichQuests(datasets[key], extras);
    const traders=new Map(bundle.traders.map(trader=>[trader.id,trader]));
    quests[mode] = bundle.tasks.map(task=>localizeQuestMediaTask(typeof task.trader==='string'?{...task,trader:traders.get(task.trader)||{id:task.trader,name:task.trader}}:task,localization));
    datasets[key] = {...bundle, tasks: []};
  }
  fs.mkdirSync(output, {recursive: true});
  for(const legacy of ['datasets.json.gz','quests.json.gz']){const file=path.join(output,legacy);if(fs.existsSync(file))fs.unlinkSync(file);}
  for (const [name, value] of Object.entries(datasets)) {
    const file=path.join(output,'datasets',name+'.gz');fs.mkdirSync(path.dirname(file),{recursive:true});
    fs.writeFileSync(file,gzipSync(JSON.stringify(value)));
  }
  fs.writeFileSync(path.join(output,'datasets.json'),JSON.stringify(Object.keys(datasets)));
  const lines=Object.entries(quests).flatMap(([mode,tasks])=>tasks.map(task=>JSON.stringify({mode,task})+'\n'));
  fs.writeFileSync(path.join(output,'quests.jsonl.gz'),gzipSync(lines.join('')));
  console.log(`Импорт: ${Object.keys(datasets).length} наборов, ${quests.pve.length + quests.regular.length} квестов PVE/PVP.`);
  return output;
}
if (process.argv[1] && path.resolve(process.argv[1]) === path.join(root, 'scripts/prepare-content-seed.mjs')) prepareContentSeed();
