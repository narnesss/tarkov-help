import {serverProfiles} from './profile-runtime.mjs';
import {publicCachedResponse} from './public-content-cache.mjs?v=lazy-20261016';

const dynamicContent=()=>serverProfiles||globalThis.document?.querySelector('meta[name="wiki-runtime"]')?.content==='php';

export async function contentBundleResponse(name,signal,request=globalThis.fetch){
  if(!['quest-extras','raid-base','quest-ui'].includes(name)||!dynamicContent())return null;
  try{
    const response=await publicCachedResponse('/api/content/bundle/'+name,{signal,request});
    if(response.ok&&response.headers.get('Content-Type')?.includes('application/json'))return response;
  }catch(error){if(signal?.aborted)throw error;}
  return null;
}

export async function contentViewResponse(path,options,signal,request=globalThis.fetch){
  if(!dynamicContent()||!/^data\/guide\/(quests|items|reference)-(pve|regular)\.json$/.test(path))return null;
  try{
    const response=await publicCachedResponse('/api/content/data/'+path.slice(5)+'?'+new URLSearchParams(options),{signal,request});
    if(response.ok&&response.headers.get('Content-Type')?.includes('application/json'))return response;
    if(response.status===404&&options.view==='detail')throw new Error('Запись недоступна или снята с публикации.');
  }catch(error){if(signal?.aborted||error.message==='Запись недоступна или снята с публикации.')throw error;}
  return null;
}

// Возвращает свежие данные CMS, а автономный экспорт использует исходный снимок.
export async function contentResponse(url, signal, request = globalThis.fetch) {
  const path = String(url).replace(/^\//, '');
  if (!path.startsWith('data/') || !path.endsWith('.json')) return null;
  if (/^data\/(?:page-routes|navigation-routes|site-render-routes|view-export|compressed)\.json$/.test(path)) return null;
  const dynamic = dynamicContent();
  if (!dynamic) return null;
  try {
    const response = await publicCachedResponse('/api/content/data/' + path.slice(5), {signal,request});
    if (response.ok && response.headers.get('Content-Type')?.includes('application/json')) return response;
  } catch (error) { if (signal?.aborted) throw error; }
  return null;
}
