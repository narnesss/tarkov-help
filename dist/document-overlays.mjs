import {documentFloors} from './document-map.mjs?v=page-content-20261017';
import {fitTransform} from './model.mjs?v=page-content-20261017';
import {contentViewResponse} from './content-client.mjs?v=documents-access-20261020';

export function accessLabel(access) {
 if(!access?.requiresKey)return '';
 const names=(access.keys||[]).map(k=>k.name).filter(name=>name&&name!=='Ключ');
 return names.length?'Нужен ключ: '+names.join(', '):'Для доступа нужен ключ';
}
export function visibleDocumentExits(data,mapId,mode,floor,enabled=true){
 if(!enabled||data?.mapId!==mapId||data.mode!==mode)return [];
 return (data.exits||[]).filter(p=>Number.isFinite(p.x)&&Number.isFinite(p.y)&&p.x>=(p.outsideArtwork===true?-5:0)&&p.x<=(p.outsideArtwork===true?105:100)&&p.y>=(p.outsideArtwork===true?-5:0)&&p.y<=(p.outsideArtwork===true?105:100)&&(floor==='all'||p.floor===floor));
}
export function createDocumentOverlayLoader(request=async(map,mode,signal)=>{
 const response=await contentViewResponse('data/maps.json',{view:'documents',map,mode},signal);
 return response.json();
}){
 let epoch=0,controller;
 return async(map,mode)=>{
  const owner=++epoch;controller?.abort();controller=new AbortController();
  try{const data=await request(map,mode,controller.signal);
   if(owner!==epoch)return null;
   if(data?.mapId!==map||data?.mode!==mode||!Array.isArray(data.exits)||!data.access||typeof data.access!=='object')throw Error('Некорректные данные карты');
   return data;
  }catch(error){if(owner!==epoch)return null;throw error;}
 };
}

/** Поле вокруг SVG оставляет проверенные приграничные выходы видимыми без переноса точек. */
export function fitDocumentOverlayView(width,height,mapWidth,mapHeight,padding){
 const view=fitTransform(width,height,mapWidth*1.1,mapHeight*1.1,Math.max(24,padding??30));
 return {...view,x:view.x+mapWidth*.05*view.scale,y:view.y+mapHeight*.05*view.scale};
}

export function documentOverlayFloors(points,map,overlay,pendingFloor=null){
 const floors=new Set(documentFloors(points));
 if(overlay?.mapId!==map.id&&map.documentPanels?.[pendingFloor])floors.add(pendingFloor);
 if(overlay?.mapId===map.id)for(const exit of overlay.exits||[]){if(map.documentPanels?.[exit.floor])floors.add(exit.floor);}
 return [...floors].sort((a,b)=>Number(a.replace('floor-',''))-Number(b.replace('floor-',''))||a.localeCompare(b));
}
