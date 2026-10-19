import {contentViewResponse} from './content-client.mjs?v=documents-access-20261020';

export function accessLabel(access) {
 if(!access?.requiresKey)return '';
 const names=(access.keys||[]).map(k=>k.name).filter(name=>name&&name!=='Ключ');
 return names.length?'Нужен ключ: '+names.join(', '):'Для доступа нужен ключ';
}
export function visibleDocumentExits(data,mapId,mode,floor,enabled=true){
 if(!enabled||data?.mapId!==mapId||data.mode!==mode)return [];
 return (data.exits||[]).filter(p=>Number.isFinite(p.x)&&Number.isFinite(p.y)&&p.x>=0&&p.x<=100&&p.y>=0&&p.y<=100&&(floor==='all'||p.floor===floor));
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
