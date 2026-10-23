import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import zlib from 'node:zlib';
import {mapCrs,pointCoordinates,worldCoordinates,floorHeight,rasterTileLayer} from '../../public/raid-map-geometry.mjs';
import {poiFloor,iconPath} from '../../public/raid-model.mjs';

const maps=JSON.parse(fs.readFileSync(new URL('../../public/data/raid/maps.json',import.meta.url)));
const L={CRS:{Simple:{}},extend:Object.assign,Transformation:class {},point:(x,y)=>({x,y}),latLng:(lat,lng)=>({lat,lng}),bounds:()=>({})};
test('границы пирамиды исключают ложные 404 Ледокола, сохраняя проверку границ карты',()=>{
  const TileLayer={prototype:{_isValidTile:c=>c.x!==1},extend:methods=>class {constructor(){Object.assign(this,methods);}}};
  const layer=rasterTileLayer({TileLayer},'',{});
  for(const c of [{x:-1,y:0,z:1},{x:0,y:-1,z:1},{x:2,y:0,z:1},{x:0,y:2,z:1},{x:1,y:0,z:1}])assert.equal(layer._isValidTile(c),false);
  assert.equal(layer._isValidTile({x:0,y:0,z:1}),true);assert.equal(layer._isValidTile({x:31,y:31,z:5}),true);
});
test('перетаскивание сохраняет мировые координаты при поворотах всех 13 карт',()=>{
  assert.equal(maps.length,13);
  for(const map of maps){
    const projection=mapCrs(L,map).projection;
    for(const [x,z] of [...map.bounds,[0,0],[-12.345,67.89]]){
      const ll=projection.unproject(projection.project({lng:x,lat:z}));
      assert.ok(Math.abs(ll.lng-x)<1e-8,map.id);assert.ok(Math.abs(ll.lat-z)<1e-8,map.id);
      assert.deepEqual(worldCoordinates(map,'all',ll,3),{x:ll.lng,y:3,z:ll.lat});
    }
  }
});
test('обратная калибровка и явный этаж совместимы с прежним публичным клиентом',()=>{
  const map={baseFloor:{id:'base'},floors:[{id:'upper',extents:[{height:[4,8]}]}],sourceCalibrations:{base:{matrix:[2,1,50,-1,3,80]}}};
  const point={position:{x:12,y:5,z:-18},floorOverride:'upper'};
  const [lat,lng]=pointCoordinates(map,'base',point);
  assert.deepEqual(worldCoordinates(map,'base',{lat,lng},5),point.position);
  assert.equal(poiFloor(map,point),'upper');assert.equal(floorHeight(map,'upper',{x:1,y:0,z:1}),4.01);
  assert.throws(()=>worldCoordinates({...map,sourceCalibrations:{base:{matrix:[1,2,0,2,4,0]}}},'base',{lat:0,lng:0},0));
});
test('последовательные пакеты сверки соответствуют seed, сохраняют категории и используют существующие иконки',()=>{
  const base=new URL('../../',import.meta.url);let additions=0,moves=0,archives=0;
  const updates=fs.readdirSync(new URL('database/content-updates/',base)).filter(f=>f.endsWith('.json')).map(f=>JSON.parse(fs.readFileSync(new URL('database/content-updates/'+f,base))));
  assert.equal(new Set(updates.map(u=>u.id)).size,updates.length,'ID всех выпусков должны быть уникальны');
  const finalFields=new Map();
  for(const update of updates.sort((a,b)=>a.id-b.id))for(const [relative,patch] of Object.entries(update.datasets||{})){
    if(!/^raid\/(?:pve\/)?[a-z-]+\.json$/.test(relative))continue;
    const records=finalFields.get(relative)||new Map();finalFields.set(relative,records);
    for(const record of patch.raidPointRecords||[])records.set(record.id,{...records.get(record.id),...record.fields});
  }
  for(const map of maps)for(const mode of ['regular','pve']){
    const update=JSON.parse(fs.readFileSync(new URL(`database/content-updates/20261020-map-${map.id}-${mode}.json`,base)));
    const relative=`raid/${mode==='pve'?'pve/':''}${map.id}.json`,file=new URL('public/data/'+relative,base),gz=new URL(file.href+'.gz');
    const dataset=JSON.parse(fs.existsSync(gz)?zlib.gunzipSync(fs.readFileSync(gz)):fs.readFileSync(file));
    assert.equal(dataset.mapId,map.id);const points=new Map(dataset.pois.map(p=>[p.id,p]));assert.equal(points.size,dataset.pois.length);
    for(const [id,fields] of finalFields.get(relative)){
      const point=points.get(id);assert.ok(point,id);
      for(const [key,value] of Object.entries(fields))assert.deepEqual(point[key],value,`${map.id}/${id}/${key}`);
      assert.ok(fs.existsSync(new URL('public/'+iconPath(point),base)));
    }
    for(const patch of update.datasets[relative].raidPointRecords){
      const point=points.get(patch.id);assert.ok(point,patch.id);
      if(patch.expected===null){additions++;assert.equal(point.sourceMapId,map.id);assert.equal(point.gameMode,mode);assert.ok(fs.existsSync(new URL('public/'+iconPath(point),base)));}
      else if(patch.fields.position)moves++;
      if(patch.fields.cmsArchived)archives++;
    }
  }
  assert.equal(additions,1390);assert.equal(moves,65);assert.equal(archives,808);
});
