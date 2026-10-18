import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
export const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const publicDir=path.join(root,'public'),dist=path.join(root,'dist');
const readJSON=name=>JSON.parse(fs.readFileSync(path.join(publicDir,name),'utf8'));
const escape=value=>String(value).replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#x27;'}[ch]));
export function routePlan(){return readJSON('data/site-render-routes.json');}
export function sourceFingerprint(){
 const names=['public/data/page-routes.json', 'config/pages.php','config/legal.php','app/Services/PageCatalog.php','app/Services/PageViews.php','app/Services/PageModules.php','app/Console/Commands/ExportSite.php'];
 function walk(dir){for(const item of fs.readdirSync(path.join(root,dir),{withFileTypes:true})){
  const name=dir+'/'+item.name;if(item.isDirectory())walk(name);else names.push(name);
 }}
 walk('resources/views');
 for(const dir of ['public','public/vendor'])for(const file of fs.readdirSync(path.join(root,dir)))if(file.endsWith('.mjs'))names.push(dir+'/'+file);
 const hash=createHash('sha256');
 for(const name of names.sort()){hash.update(name+'\0'+fs.readFileSync(path.join(root,name),'utf8').replaceAll('\r\n','\n')+'\0');}
 return hash.digest('hex');
}
export function build(){
 const render=spawnSync(process.env.PHP_BINARY||'php',['artisan','site:export'],{cwd:root,encoding:'utf8'});
 if(render.error?.code!=='ENOENT'&&render.status!==0)throw Error(render.stderr||render.stdout||'Laravel export failed');
 if(readJSON('data/view-export.json').source_hash!==sourceFingerprint())throw Error('Blade export is stale. Run composer install and php artisan site:export before building.');
 const expected=new Set();
 const write=(filename,bytes)=>{
  expected.add(path.relative(dist,filename));fs.mkdirSync(path.dirname(filename),{recursive:true});
  if(!fs.existsSync(filename)||!fs.readFileSync(filename).equals(Buffer.from(bytes)))fs.writeFileSync(filename,bytes);
 };
 function copy(dir){for(const item of fs.readdirSync(dir,{withFileTypes:true})){
  if(item.name.startsWith('.')||item.name.endsWith('.php'))continue;
  const relative=path.relative(publicDir,path.join(dir,item.name)).split(path.sep).join('/');
  if(relative.startsWith('data/')&&!['data/page-routes.json','data/navigation-routes.json','data/site-render-routes.json','data/view-export.json'].includes(relative))continue;
  if(dir===path.join(publicDir,'vendor')&&item.name==='orchid')continue;
  const source=path.join(dir,item.name),target=path.join(dist,path.relative(publicDir,source));
  if(item.isDirectory())copy(source);else write(target,fs.readFileSync(source));
 }}
 copy(publicDir);
 const routes=routePlan(),templates={};
 for(const entry of Object.values(routes))templates[entry.template]??=fs.readFileSync(path.join(dist,entry.template+'.html'),'utf8');
 for(const [url,entry] of Object.entries(routes)){
  const variant=entry.template==='boss'&&Object.hasOwn(entry.params,'boss')?'boss-card':entry.template==='reference'&&Object.hasOwn(entry.params,'item')?'item-card':null;
  const template=variant?fs.readFileSync(path.join(dist,variant+'.html'),'utf8'):templates[entry.template];
  let source=template.replace(/<script type="application\/json" id="wiki-page-params">.*?<\/script>\s*/s,'').replace(/<title>.*?<\/title>/,'<title>'+escape(entry.title)+' — Escape from Tarkov Wiki</title>');
  const params=JSON.stringify(entry.params).replaceAll('":','": ').replaceAll(',',', ').replaceAll('<','\\u003c');
  source=source.replace('</head>','<script type="application/json" id="wiki-page-params">'+params+'</script>\n</head>');
  const target=path.resolve(dist,url.slice(1)+'.html');if(!url.startsWith('/')||!target.startsWith(dist+path.sep))throw Error('Unsafe export route: '+url);write(target,source);
 }
 function prune(dir){if(!fs.existsSync(dir))return;for(const item of fs.readdirSync(dir,{withFileTypes:true})){
  const filename=path.join(dir,item.name);if(item.isDirectory())prune(filename);
  else if(!expected.has(path.relative(dist,filename)))fs.unlinkSync(filename);
 }}
 prune(dist);
 console.log('Cloudflare export: '+Object.keys(routes).length+' physical routes.');
}
if(process.argv[1]&&import.meta.url===pathToFileURL(path.resolve(process.argv[1])).href)build();
