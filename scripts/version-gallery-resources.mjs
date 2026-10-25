import fs from 'node:fs';
import path from 'node:path';
import {createHash} from 'node:crypto';

// Версии общего просмотрщика и его потребителей зависят от байтов всего графа.
export function galleryVersions(resources){
 const clean=source=>source.replace(/(\.mjs)\?v=[a-zA-Z0-9_-]+/g,'$1').replaceAll('\r\n','\n');
 const dependencies=new Map([...resources].map(([name,source])=>[name,[...source.matchAll(/['"]\.\/([a-zA-Z0-9_.-]+\.mjs)(?:\?v=[a-zA-Z0-9_-]+)?['"]/g)].map(match=>match[1])]));
 dependencies.set('gallery.mjs',[...(dependencies.get('gallery.mjs')||[]),'guide.css']);
 const affected=new Set(['gallery.mjs']);
 for(let changed=true;changed;){changed=false;for(const [name,deps] of dependencies)if(!affected.has(name)&&deps.some(dep=>affected.has(dep))){affected.add(name);changed=true;}}
 return new Map([...affected].map(name=>{
  const reachable=new Set();
  const visit=file=>{if(reachable.has(file)||!resources.has(file))return;reachable.add(file);for(const dep of dependencies.get(file)||[])visit(dep);};visit(name);
  const hash=createHash('sha256');for(const file of [...reachable].sort())hash.update(file+'\0'+clean(resources.get(file))+'\0');
  return [name,hash.digest('hex').slice(0,16)];
 }));
}

export function versionGalleryResources(root){
 const publicDir=path.join(root,'public'),resources=new Map();
 for(const name of fs.readdirSync(publicDir))if(name.endsWith('.mjs')||name==='guide.css')resources.set(name,fs.readFileSync(path.join(publicDir,name),'utf8'));
 const versions=galleryVersions(resources),targets=[...resources.keys()].filter(name=>name.endsWith('.mjs')).map(name=>path.join(publicDir,name));
 versions.set('guide.css',createHash('sha256').update(resources.get('guide.css').replaceAll('\r\n','\n')).digest('hex').slice(0,16));
 targets.push(path.join(root,'config/pages.php'),path.join(root,'app/Services/PageModules.php'));
 const walk=dir=>{for(const item of fs.readdirSync(dir,{withFileTypes:true})){const file=path.join(dir,item.name);if(item.isDirectory())walk(file);else if(file.endsWith('.blade.php'))targets.push(file);}};walk(path.join(root,'resources/views'));
 for(const file of targets){
  const before=fs.readFileSync(file,'utf8');let after=before;
  for(const [name,version] of versions)after=after.replace(new RegExp('(?<![a-zA-Z0-9_.-])'+name.replaceAll('.','\\.')+'\\?v=[a-zA-Z0-9_-]+','g'),name+'?v='+version);
  if(before!==after)fs.writeFileSync(file,after);
 }
}
