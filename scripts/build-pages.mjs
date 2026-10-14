import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {spawnSync} from 'node:child_process';
export const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const publicDir=path.join(root,'public'),dist=path.join(root,'dist');
const readJSON=name=>JSON.parse(fs.readFileSync(path.join(publicDir,name),'utf8'));
const escape=value=>String(value).replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#x27;'}[ch]));
export function routePlan(){
 const index=readJSON('data/page-routes.json'),result={};
 const add=(url,template,title,params)=>{if(result[url])throw Error('Duplicate route: '+url);result[url]={template,title,params};};
 for(const [id,item] of Object.entries(index.quests)){add('/quests/'+item.slug,'quest',item.name,{quest:id});if(id!==item.slug)add('/quests/'+id,'quest',item.name,{quest:id});}
 for(const [id,item] of Object.entries(index.traders)){add('/quests/trader/'+item.slug,'quests','Квесты: '+item.name,{trader:id});if(id!==item.slug)add('/quests/trader/'+id,'quests','Квесты: '+item.name,{trader:id});}
 for(const [section,title] of Object.entries({all:'Все квесты',story:'Основной сюжет',archive:'Архив квестов'}))add('/quests/'+section,'quests',title,{section});
 for(const [group,prefix,template,key] of [['maps','raid','raid','map'],['documents','documents','documents','map'],['bosses','boss','boss','boss'],['sourceMaps','quest-map','quest-map-source','map']]){
  for(const [id,item] of Object.entries(index[group]))add('/'+prefix+'/'+id,template,item.name,{[key]:id});
 }
 for(const [id,item] of Object.entries(index.items))add('/reference/items/'+id,'reference',item.name,{tab:'items',item:id});
 for(const [tab,title] of Object.entries({items:'Предметы',ammo:'Патроны',needed:'Для квестов',barters:'Бартеры',crafts:'Крафты',hideout:'Убежище',bosses:'Боссы',traders:'Торговцы',achievements:'Достижения',btr:'Маршруты БТР'}))add('/reference/'+tab,'reference',title,{tab});
 return result;
}
export function build(){
 const render=spawnSync(process.env.PHP_BINARY||'php',['bin/console','export:static'],{cwd:root,encoding:'utf8'});
 if(render.error?.code!=='ENOENT'&&render.status!==0)throw Error(render.stderr||'PHP export failed');
 if(render.error?.code==='ENOENT'){
  // These full-page templates currently contain only the escaped runtime marker.
  // Reject new PHP logic instead of silently publishing stale output.
  for(const filename of fs.readdirSync(path.join(root,'templates/pages')).filter(name=>name.endsWith('.php'))){
   let source=fs.readFileSync(path.join(root,'templates/pages',filename),'utf8');
   source=source.replaceAll('<?= $escape($runtime) ?>','static');
   if(source.includes('<?'))throw Error('PHP CLI required to export '+filename);
   fs.writeFileSync(path.join(publicDir,filename.replace(/\.php$/,'.html')),source);
  }
 }
 fs.rmSync(dist,{recursive:true,force:true});
 fs.cpSync(publicDir,dist,{recursive:true,filter:source=>!path.basename(source).startsWith('.')&&!source.endsWith('.php')});
 for(const prefix of ['quests','raid','documents','boss','reference','quest-map'])fs.rmSync(path.join(dist,prefix),{recursive:true,force:true});
 const routes=routePlan(),templates={};
 for(const entry of Object.values(routes))templates[entry.template]??=fs.readFileSync(path.join(dist,entry.template+'.html'),'utf8');
 for(const [url,entry] of Object.entries(routes)){
  let source=templates[entry.template].replace(/<title>.*?<\/title>/,'<title>'+escape(entry.title)+' — Escape from Tarkov Wiki</title>');
  const params=JSON.stringify(entry.params).replaceAll('":','": ').replaceAll(',',', ').replaceAll('<','\\u003c');
  source=source.replace('</head>','<script type="application/json" id="wiki-page-params">'+params+'</script>\n</head>');
  const target=path.join(dist,url.slice(1)+'.html');fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,source);
 }
 console.log('Pages export: '+Object.keys(routes).length+' physical routes.');
}
if(process.argv[1]&&import.meta.url===pathToFileURL(path.resolve(process.argv[1])).href)build();
