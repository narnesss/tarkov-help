import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import {createHash} from 'node:crypto';
import {fileURLToPath} from 'node:url';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const option=name=>process.argv.find(x=>x.startsWith('--'+name+'='))?.slice(name.length+3);
const mapId=option('map'),date=option('date')||'2026-10-20',firstId=Number(option('first-id')||2026102031);
if(!/^\d{4}-\d{2}-\d{2}$/.test(date)||!Number.isSafeInteger(firstId)||firstId<1)throw Error('Некорректная дата или первый ID пакета.');
const release=date.replaceAll('-','');
const sourceDir=process.argv.find(x=>x.startsWith('--source='))?.slice(9);
if(!sourceDir)throw Error('Укажите --source=каталог со снимками regular.json, pve.json, ru.json, tasks-regular.json, tasks-pve.json');
const apply=process.argv.includes('--apply'),read=file=>JSON.parse(fs.readFileSync(file)),hash=value=>createHash('sha256').update(value).digest('hex');
const readData=relative=>{const base=path.join(root,'public/data',relative);return fs.existsSync(base+'.gz')?JSON.parse(zlib.gunzipSync(fs.readFileSync(base+'.gz'))):read(base);};
const writeData=(relative,data)=>{const base=path.join(root,'public/data',relative),bytes=JSON.stringify(data)+'\n';fs.writeFileSync(fs.existsSync(base+'.gz')?base+'.gz':base,fs.existsSync(base+'.gz')?zlib.gzipSync(bytes,{level:9}):bytes);};
const allDefinitions=readData('raid/maps.json'),definitions=allDefinitions.filter(d=>!mapId||d.id===mapId),names=readData('raid/item-names.json'),ru=read(path.join(sourceDir,'ru.json')).data;
if(!definitions.length)throw Error('Неизвестная карта: '+mapId);
const referencePath=path.join(sourceDir,'definitions.json');
if(!fs.existsSync(referencePath))throw Error('Добавьте definitions.json из src/data/maps.json репозитория tarkov.dev.');
const reference=read(referencePath),calibrations=[];
for(const definition of definitions){
  const source=reference.find(g=>g.normalizedName===definition.id)?.maps.find(m=>m.key===definition.id);if(!source)throw Error('Нет определения карты '+definition.id);
  const fields={},expected={};
  for(const key of ['transform','bounds','svgBounds'])if(source[key]&&JSON.stringify(definition[key])!==JSON.stringify(source[key])){fields[key]=source[key];expected[key]=definition[key]??null;}
  if(Object.keys(fields).length){calibrations.push({id:definition.id,expected,fields});Object.assign(definition,fields);}
}
if(apply&&calibrations.length){const filename=path.join(root,'database/content-updates/'+release+'-map-calibration.json');if(fs.existsSync(filename))throw Error('Пакет калибровки уже существует; создайте новый выпуск.');fs.writeFileSync(filename,JSON.stringify({schemaVersion:1,id:date==='2026-10-20'?2026102099:firstId+definitions.length*2,description:'Калибровка карт по определениям tarkov.dev',datasets:{'raid/maps.json':{records:calibrations}}})+'\n');writeData('raid/maps.json',allDefinitions);}
const positionKey=p=>['x','y','z'].map(k=>Number(p[k]).toFixed(3)).join('/'),distance=(a,b)=>Math.hypot(a.x-b.x,a.y-b.y,a.z-b.z);
const inside=(p,d)=>p.x>=Math.min(d.bounds[0][0],d.bounds[1][0])&&p.x<=Math.max(d.bounds[0][0],d.bounds[1][0])&&p.z>=Math.min(d.bounds[0][1],d.bounds[1][1])&&p.z<=Math.max(d.bounds[0][1],d.bounds[1][1]);
const report=[];
for(const mode of ['regular','pve']){
  const bytes=fs.readFileSync(path.join(sourceDir,mode+'.json')),api=JSON.parse(bytes).data,tasks=read(path.join(sourceDir,'tasks-'+mode+'.json')).data.tasks;
  const localTasks=readData('guide/quests-'+mode+'.json').tasks,taskNames=new Map(Object.values(localTasks).map(q=>[q.id,q.name]));
  for(const definition of definitions){
    const m=Object.values(api.maps).find(m=>m.normalizedName===definition.id);if(!m)throw Error('Карта отсутствует: '+definition.id);
    const relative='raid/'+(mode==='pve'?'pve/':'')+definition.id+'.json',payload=readData(relative),original=structuredClone(payload),source=[],used=new Set(),changes=[];
    const add=(kind,category,record,fields={})=>{if(!record.position||!inside(record.position,definition))return;source.push({kind,category,name:ru[record.name]||record.name||category,position:record.position,...fields});};
    for(const p of m.extracts)add('extract','extract-'+(p.faction||'shared'),p,{sourceId:p.id,faction:p.faction,outline:p.outline||[]});
    for(const p of m.lootContainers){const type=api.lootContainers[p.lootContainer];add('loot','loot',p,{name:ru[type.name]||type.name,containerId:p.lootContainer,lootType:type.normalizedName});}
    for(const p of m.lootLoose)add('loose-loot','loose-loot',p,{name:p.items.map(id=>names[id]||id).join(' · '),itemIds:p.items,itemNames:p.items.map(id=>names[id]||id)});
    for(const p of m.locks)add('locked-door','locked-door',p,{name:names[p.key]||'Запертая дверь',sourceId:p.id,keyIds:[p.key],keyNames:[names[p.key]||'Ключ']});
    for(const p of m.hazards)add('hazard','hazard',p,{sourceId:p.id,hazardType:p.hazardType,outline:p.outline||[]});
    for(const p of m.switches)add('switch','switch',p,{sourceId:p.id});
    for(const p of m.transits)add('transit','transit',p,{name:ru[p.description]||'Переход',sourceId:p.id,targetMapId:api.maps[p.map]?.normalizedName,outline:p.outline||[]});
    for(const p of m.stationaryWeapons)add('stationary-weapon','stationary-weapon',p,{name:ru[api.stationaryWeapons[p.stationaryWeapon]?.name]||'Стационарное оружие',weaponId:p.stationaryWeapon});
    for(const p of m.btrStops||[])add('btr','btr',p,{sourceId:p.id,name:ru[p.name]||p.name||'Остановка БТР'});
    for(const p of m.spawns){
      const bosses=p.categories.includes('boss')?m.bosses.filter(b=>b.spawnLocations.some(s=>s.spawnKey===p.zoneName)):[];
      let category,name;
      if(bosses.length){category='boss-zone';name=[...new Set(bosses.map(b=>ru[api.mobs[b.mob]?.name]||api.mobs[b.mob]?.normalizedName||b.mob))].join(' · ');}
      else if(p.categories.includes('boss')){if(!p.categories.includes('bot')||!p.sides.includes('scav'))continue;category='spawn-scav';name='Спавн Дикого';}
      else if(p.categories.includes('player')){if(!p.sides.some(s=>s==='pmc'||s==='all'))continue;category='spawn-pmc';name='Спавн ЧВК';}
      else if(p.categories.includes('sniper')){category='spawn-sniper';name='Спавн снайпера';}
      else if(p.sides.includes('scav')&&p.categories.some(c=>c==='bot'||c==='all')){category='spawn-scav';name='Спавн Дикого';}
      else continue;
      add(category==='boss-zone'?'boss-zone':'spawn',category,p,{name,sourceZone:p.zoneName,sourceId:p.zoneName,bossId:bosses[0]?.mob});
    }
    for(const q of Object.values(tasks))for(const objective of q.objectives||[]){
      const positions=[...(objective.possibleLocations||[]).filter(l=>l.map===m.id).flatMap(l=>l.positions.map(position=>({position}))),...(objective.zones||[]).filter(z=>z.map===m.id)];
      for(const p of positions)add('quest','quest',p,{name:taskNames.get(q.id)||'Квестовое место',questId:q.id,objectiveId:objective.id,description:payload.pois.find(p=>p.objectiveId===objective.id)?.description||'',...(p.outline?{outline:p.outline}:{}),...(objective.questItem?{itemIds:[objective.questItem]}:{})});
    }
    const identity=p=>p.category==='quest'?p.questId+'/'+p.objectiveId:p.category==='loot'?p.containerId:p.category==='loose-loot'?[...(p.itemIds||[])].sort().join(','):p.category==='locked-door'?[...(p.keyIds||[])].sort().join(','):p.category==='stationary-weapon'?p.weaponId:'';
    // Только однозначный внешний ID или точная позиция того же типа. Центроиды спавнов разбираются по исходным позициям.
    const distinctSource=[...new Map(source.map(p=>[p.category+'\0'+identity(p)+'\0'+positionKey(p.position),p])).values()];
    for(const p of distinctSource){
      // Явный архив и ручная позиция не отменяются повторным импортом источника.
      const protectedPoint=payload.pois.find(old=>((old.cmsArchived&&!old.archiveReason?.startsWith('Усреднённая группа заменена'))||old.editorPosition||old.sourceStatus==='editor')&&old.category===p.category&&
        ((p.sourceId&&old.sourceId===p.sourceId&&!['spawn','boss-zone'].includes(p.kind))||
        !(old.sourcePoints?.length>1)&&identity(old)===identity(p)&&distance(old.position,p.position)<.08));
      if(protectedPoint){used.add(protectedPoint.id);continue;}
      let matches=payload.pois.filter(old=>!used.has(old.id)&&!old.cmsArchived&&!old.editorPosition&&old.sourceStatus!=='editor'&&old.category===p.category&&
        ((p.sourceId&&old.sourceId===p.sourceId&&!['spawn','boss-zone'].includes(p.kind))||identity(old)===identity(p)&&distance(old.position,p.position)<.08));
      if(matches.length===0&&['spawn','boss-zone'].includes(p.kind))matches=payload.pois.filter(old=>!used.has(old.id)&&!old.cmsArchived&&!old.editorPosition&&old.category===p.category&&
        (old.sourcePoints||[]).some(v=>Math.hypot(v[0]-p.position.x,v[1]-p.position.z)<.08));
      matches.sort((a,b)=>distance(a.position,p.position)-distance(b.position,p.position));
      const old=matches.length===1||matches.length>1&&distance(matches[0].position,p.position)<.00001?matches[0]:null;
      if(old){used.add(old.id);const fields={};if(distance(old.position,p.position)>.001)fields.position=p.position;
        if(old.hiddenByDefault&&['current','supplemental'].includes(old.sourceStatus)){
          fields.hiddenByDefault=false;fields.sourceStatus='current';
          // Прежнее предупреждение больше не соответствует подтверждённой точке источника.
          if(old.sourceNote)fields.sourceNote=null;
        }
        if(Object.keys(fields).length){fields.coordinateSource='https://tarkov.dev/map/'+definition.id;fields.coordinateDate=date;changes.push({id:old.id,expected:{position:old.position,sourceStatus:old.sourceStatus??null,hiddenByDefault:old.hiddenByDefault??null,sourceNote:old.sourceNote??null},fields});Object.assign(old,fields);}
      }else{
        const key=p.category+'\0'+identity(p)+'\0'+positionKey(p.position);let id='tarkov-dev-'+hash(definition.id+'\0'+mode+'\0'+key).slice(0,24);
        // Совпадающие записи одного источника не превращаем в дубликаты меток.
        if(payload.pois.some(x=>x.id===id)){used.add(id);continue;}
        const fields={...p,gameMode:mode,sourceMapId:definition.id,sourceStatus:'current',coordinateSource:'https://tarkov.dev/map/'+definition.id,coordinateDate:date};
        changes.push({id,expected:null,fields});payload.pois.push({id,...fields});used.add(id);
      }
    }
    for(const old of original.pois){if(used.has(old.id)||old.cmsArchived||old.editorPosition||!old.navigator||!['spawn','boss-zone'].includes(old.kind))continue;
      const fields={cmsArchived:true,archiveReason:'Усреднённая группа заменена отдельными позициями tarkov.dev; ID и связи сохранены.'};changes.push({id:old.id,expected:{position:old.position,sourceStatus:old.sourceStatus??null},fields});Object.assign(payload.pois.find(p=>p.id===old.id),fields);
    }
    const summary={map:definition.id,mode,source:source.length,confirmed:changes.filter(x=>x.fields.hiddenByDefault===false).length,added:changes.filter(x=>x.expected===null).length,addedKinds:Object.fromEntries([...new Set(changes.filter(x=>x.expected===null).map(x=>x.fields.kind))].map(k=>[k,changes.filter(x=>x.expected===null&&x.fields.kind===k).length])),moved:changes.filter(x=>x.expected&&x.fields.position).length,archivedAggregates:changes.filter(x=>x.fields.cmsArchived).length};report.push(summary);
    fs.mkdirSync(path.join(root,'build/map-sync-review'),{recursive:true});fs.writeFileSync(path.join(root,'build/map-sync-review',definition.id+'-'+mode+'.json'),JSON.stringify(changes,null,2));
    if(apply&&changes.length){const filename=path.join(root,'database/content-updates',release+'-map-'+definition.id+'-'+mode+'.json');if(fs.existsSync(filename))throw Error('Пакет уже существует; для следующей сверки создайте новый выпуск: '+filename);const update={schemaVersion:1,id:firstId+definitions.indexOf(definition)+(mode==='pve'?definitions.length:0),description:'Сверка точек '+definition.name+' / '+mode+' с tarkov.dev',source:{url:'https://tarkov.dev/map/'+definition.id,api:'https://json.tarkov.dev/'+mode+'/maps',retrievedAt:fs.statSync(path.join(sourceDir,mode+'.json')).mtime.toISOString(),sha256:hash(bytes),tasksSha256:hash(fs.readFileSync(path.join(sourceDir,'tasks-'+mode+'.json'))),definitionsSha256:hash(fs.readFileSync(referencePath)),mode},datasets:{[relative]:{raidPointRecords:changes}}};fs.writeFileSync(filename,JSON.stringify(update)+'\n');writeData(relative,payload);}
  }
}
fs.mkdirSync(path.join(root,'build'),{recursive:true});fs.writeFileSync(path.join(root,'build/tarkov-map-sync-report.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report));
