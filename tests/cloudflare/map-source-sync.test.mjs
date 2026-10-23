import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {scopedRaidPoints} from '../../public/raid-scope.mjs';

test('сверка подтверждает скрытые спавны выбранной карты, сохраняет архив и повторяется без изменений',()=>{
 const root=fs.mkdtempSync(path.join(os.tmpdir(),'wiki-map-sync-'));
 assert.equal(path.dirname(root),path.resolve(os.tmpdir()));assert.ok(path.basename(root).startsWith('wiki-map-sync-'));
 const write=(name,value)=>{const file=path.join(root,name);fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,JSON.stringify(value));};
 const read=name=>JSON.parse(fs.readFileSync(path.join(root,name)));
 try{
  fs.mkdirSync(path.join(root,'database/content-updates'),{recursive:true});
  fs.mkdirSync(path.join(root,'scripts'));fs.copyFileSync(new URL('../../scripts/sync-tarkov-map-points.mjs',import.meta.url),path.join(root,'scripts/sync-tarkov-map-points.mjs'));
  const definition={id:'shoreline',name:'Берег',bounds:[[100,0],[0,100]],transform:[1,0,1,0]};
  write('public/data/raid/maps.json',[definition,{...definition,id:'woods'}]);
  write('public/data/raid/item-names.json',{});write('snapshots/ru.json',{data:{}});
  write('snapshots/definitions.json',[{normalizedName:'shoreline',maps:[{key:'shoreline',bounds:definition.bounds,transform:definition.transform}]}]);
  const spawn={position:{x:25,y:0,z:40},categories:['player'],sides:['pmc'],zoneName:'stable-zone'};
  const raw={normalizedName:'shoreline',id:'map',extracts:[{id:'old-exit',faction:'pmc',name:'Архив',position:{x:10,y:0,z:20}}],lootContainers:[],lootLoose:[],locks:[],hazards:[],switches:[],transits:[],stationaryWeapons:[],spawns:[spawn],bosses:[]};
  for(const mode of ['regular','pve']){
   const relative='public/data/raid/'+(mode==='pve'?'pve/':'');
   write(relative+'shoreline.json',{mapId:'shoreline',pois:[
    {id:'foreign-spawn',kind:'spawn',category:'spawn-pmc',position:spawn.position,gameMode:mode==='pve'?'regular':'pve',sourceStatus:'supplemental',hiddenByDefault:true,navigator:{layer:'spawns'}},
    {id:'stable-spawn',kind:'spawn',category:'spawn-pmc',name:'Спавн',position:spawn.position,sourceStatus:'supplemental',hiddenByDefault:true,sourceNote:'Не подтверждена',gameMode:mode,custom:'Сохранить'},
    {id:'old-exit',kind:'extract',category:'extract-pmc',position:raw.extracts[0].position,sourceId:'old-exit',cmsArchived:true},
    {id:'editor',kind:'spawn',category:'spawn-pmc',position:{x:50,y:0,z:50},sourceStatus:'editor',hiddenByDefault:true}
   ]});
   write(relative+'woods.json',{sentinel:mode});
   write('snapshots/'+mode+'.json',{data:{maps:{map:raw}}});
   write('snapshots/tasks-'+mode+'.json',{data:{tasks:[]}});write('public/data/guide/quests-'+mode+'.json',{tasks:[]});
  }
  const run=apply=>{
   const result=spawnSync(process.execPath,[path.join(root,'scripts/sync-tarkov-map-points.mjs'),'--source='+path.join(root,'snapshots'),'--map=shoreline','--date=2026-10-23','--first-id=2026102301',...(apply?['--apply']:[])],{encoding:'utf8',timeout:15000});
   assert.equal(result.status,0,result.stderr);return JSON.parse(result.stdout);
  };
  assert.deepEqual(run(false).map(x=>[x.confirmed,x.added]),[[1,0],[1,0]]);
  assert.equal(read('public/data/raid/shoreline.json').pois[0].hiddenByDefault,true);
  assert.deepEqual(run(true).map(x=>x.confirmed),[1,1]);
  for(const [index,mode] of ['regular','pve'].entries()){
   const p=read('public/data/raid/'+(mode==='pve'?'pve/':'')+'shoreline.json').pois;
   assert.equal(p.length,4);const foreign=p.shift();assert.equal(foreign.id,'foreign-spawn');assert.equal(foreign.hiddenByDefault,true);assert.equal(foreign.sourceStatus,'supplemental');assert.equal(foreign.cmsArchived,undefined);assert.equal(p[0].id,'stable-spawn');assert.equal(p[0].hiddenByDefault,false);assert.equal(p[0].sourceStatus,'current');assert.equal(p[0].sourceNote,null);assert.equal(p[0].custom,'Сохранить');
   assert.equal(p[1].cmsArchived,true);assert.equal(p[2].sourceStatus,'editor');assert.equal(p[2].hiddenByDefault,true);
   assert.deepEqual(scopedRaidPoints({mapId:'shoreline',pois:p},'shoreline',mode).filter(x=>!x.hiddenByDefault).map(x=>x.id),['stable-spawn']);
   const patch=read('database/content-updates/20261023-map-shoreline-'+mode+'.json');assert.equal(patch.id,2026102301+index);assert.equal(patch.source.mode,mode);assert.match(patch.source.sha256,/^[a-f0-9]{64}$/);
   assert.deepEqual(read('public/data/raid/'+(mode==='pve'?'pve/':'')+'woods.json'),{sentinel:mode});
  }
  assert.deepEqual(run(false).map(x=>[x.confirmed,x.added,x.moved,x.archivedAggregates]),[[0,0,0,0],[0,0,0,0]]);
 }finally{fs.rmSync(root,{recursive:true,force:true});}
});
