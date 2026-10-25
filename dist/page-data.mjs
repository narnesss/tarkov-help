import {contentViewResponse} from './content-client.mjs?v=documents-access-20261020';
import {freshDataset} from './guide-common.mjs?v=documents-access-20261020';

const pending=new Map();
const keyOf=(path,options)=>path+'?'+new URLSearchParams(options);
async function read(key,path,options,signal){
  const response=await contentViewResponse(path,options,signal);
  return response?response.json():freshDataset(key,path,signal);
}
// Публичная предзагрузка расходуется однократно; обновление снова проверяет сервер.
export function preloadPageView(path,options){
  options={view:'catalog',...options};
  const key=keyOf(path,options);if(pending.has(key))return;
  if(pending.size>=3)pending.delete(pending.keys().next().value);
  const promise=read(path,path,options);pending.set(key,promise);
  promise.catch(()=>{if(pending.get(key)===promise)pending.delete(key);});
}
export function pageDataset(key,path,options={view:'catalog'},signal){
  options={view:'catalog',...options};
  const slot=keyOf(path,options),promise=!signal&&pending.get(slot);pending.delete(slot);
  return promise||read(key,path,options,signal);
}
