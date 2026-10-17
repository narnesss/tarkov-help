import {publicCachedResponse} from './public-content-cache.mjs?v=page-content-20261017';

const metadata=/^data\/(?:page-routes|navigation-routes|site-render-routes|view-export|compressed)\.json$/;
/** Игровые данные читаются только у backend; ошибка API не подменяется снимком в frontend. */
async function backendResponse(url,signal,request){
 const response=await publicCachedResponse(url,{signal,request});
 if(response.ok&&response.headers.get('Content-Type')?.includes('application/json'))return response;
 if(response.status===404)throw new Error('Запись недоступна или снята с публикации.');
 throw new Error('Не удалось загрузить данные с сервера. Повторите попытку.');
}
export async function contentBundleResponse(name,signal,request=globalThis.fetch,scope={}){
 if(!['quest-extras','raid-base','quest-ui'].includes(name))return null;
 const query=new URLSearchParams(scope);
 return backendResponse('/api/content/bundle/'+name+(query.size?'?'+query:''),signal,request);
}
export async function contentViewResponse(path,options,signal,request=globalThis.fetch){
 if(!/^data\/guide\/(?:(?:quests|items|reference)-(?:pve|regular)|bosses|quest-map-index)\.json$/.test(path))return null;
 return backendResponse('/api/content/data/'+path.slice(5)+'?'+new URLSearchParams(options),signal,request);
}
export async function contentResponse(value,signal,request=globalThis.fetch){
 const path=String(value).replace(/^\//,'').split(/[?#]/,1)[0].replace(/\.json\.gz$/,'.json');
 if(!path.startsWith('data/')||!path.endsWith('.json')||metadata.test(path))return null;
 return backendResponse('/api/content/data/'+path.slice(5),signal,request);
}
