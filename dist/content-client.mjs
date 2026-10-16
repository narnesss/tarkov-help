import {serverProfiles} from './profile-runtime.mjs';

const dynamicContent=()=>serverProfiles||globalThis.document?.querySelector('meta[name="wiki-runtime"]')?.content==='php';

export async function contentBundleResponse(name,signal,request=globalThis.fetch){
  if(!['quest-extras','raid-base'].includes(name)||!dynamicContent())return null;
  try{
    const response=await request('/api/content/bundle/'+name,{signal,cache:'no-store',credentials:'omit'});
    if(response.ok&&response.headers.get('Content-Type')?.includes('application/json'))return response;
  }catch(error){if(signal?.aborted)throw error;}
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
    const response = await request('/api/content/data/' + path.slice(5), {signal, cache: 'no-store', credentials: 'omit'});
    if (response.ok && response.headers.get('Content-Type')?.includes('application/json')) return response;
  } catch (error) { if (signal?.aborted) throw error; }
  return null;
}
