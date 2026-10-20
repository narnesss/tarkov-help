import {projectPosition,displayPosition,projectRaster} from './raid-model.mjs';

// Растровые пирамиды tarkov.dev содержат только квадрат 0..2^z, без отрицательных тайлов.
export function rasterTileLayer(L,url,options){
  const Layer=L.TileLayer.extend({_isValidTile(c){return c.x>=0&&c.y>=0&&c.x<2**c.z&&c.y<2**c.z&&L.TileLayer.prototype._isValidTile.call(this,c);}});
  return new Layer(url,options);
}

export function sourceCalibration(definition,floor){return definition.sourceCalibrations?.[floor]||definition.sourceCalibrations?.[definition.baseFloor.id];}
export function mapCrs(L,definition){
  if(definition.documentMap||sourceCalibration(definition,definition.baseFloor.id))return L.CRS.Simple;
  return L.extend({},L.CRS.Simple,{transformation:new L.Transformation(1,0,1,0),projection:{
    project(ll){const p=projectPosition(definition,{x:ll.lng,z:ll.lat});return L.point(p.x,p.y);},
    unproject(p){const [sx,mx,sz,mz]=definition.transform,x=(p.x-mx)/sx,z=-(p.y-mz)/sz,a=-(definition.coordinateRotation||0)*Math.PI/180;return L.latLng(x*Math.sin(a)+z*Math.cos(a),x*Math.cos(a)-z*Math.sin(a));},
    bounds:L.bounds([-Infinity,-Infinity],[Infinity,Infinity]),
  }});
}
export function pointCoordinates(definition,floor,p){
  const position=displayPosition(definition,p);
  if(definition.documentMap){const v=p.documentPixel?{x:p.documentPixel[0],y:p.documentPixel[1]}:projectRaster(definition,position,p.floor);return [-v.y,v.x];}
  const source=sourceCalibration(definition,floor);
  if(source){const [a,b,c,d,e,f]=source.matrix;return [-(d*position.x+e*position.z+f),a*position.x+b*position.z+c];}
  return [position.z,position.x];
}
export function worldCoordinates(definition,floor,ll,y){
  const source=sourceCalibration(definition,floor);
  if(!source)return {x:ll.lng,y,z:ll.lat};
  const [a,b,c,d,e,f]=source.matrix,det=a*e-b*d;if(Math.abs(det)<1e-10)throw Error('Некорректная калибровка карты');
  const x=ll.lng-c,z=-ll.lat-f;return {x:(x*e-b*z)/det,y,z:(a*z-x*d)/det};
}
export function floorHeight(definition,floor,position){
  const target=definition.floors.find(f=>f.id===floor),inside=(p,b)=>p.x>=Math.min(b[0][0],b[1][0])&&p.x<=Math.max(b[0][0],b[1][0])&&p.z>=Math.min(b[0][1],b[1][1])&&p.z<=Math.max(b[0][1],b[1][1]);
  const extent=target?.extents?.find(e=>!e.bounds?.length||e.bounds.some(b=>inside(position,b)));
  const range=extent?.height||(floor===definition.baseFloor.id?definition.heightRange:null);
  if(!range)return position.y||0;
  return Math.max(range[0]+.01,Math.min(range[1]-.01,position.y||0));
}
