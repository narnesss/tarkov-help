/** Page routing is independent of the profile runtime (PHP or browser storage). */
let routes={};
export const isPHP=()=>globalThis.document?.querySelector('meta[name="wiki-runtime"]')?.content==='php';
export const usesPageRoutes=()=>isPHP()||globalThis.document?.querySelector('meta[name="wiki-routing"]')?.content==='pages';
export function configureSiteRoutes(index){routes=index||{};}
const base=()=>globalThis.location?.origin||'https://wiki.local';
const idFor=(kind,slug)=>Object.entries(routes[kind]||{}).find(([id,entry])=>id===slug||entry.slug===slug)?.[0]||slug;
export function pageParams(value=globalThis.location?.href||base()+'/'){
 const url=new URL(value,base()+'/'),params=new URLSearchParams(url.search),parts=url.pathname.split('/').filter(Boolean).map(decodeURIComponent);
 if(parts.length>1)parts[parts.length-1]=parts.at(-1).replace(/\.(html|php)$/,'');
 if(parts[0]==='quests'&&parts.length>1){
  params.delete('quest');params.delete('trader');params.delete('section');
  if(parts[1]==='trader'&&parts[2])params.set('trader',idFor('traders',parts[2]));
  else if(['story','all','archive'].includes(parts[1]))params.set('section',parts[1]);
  else params.set('quest',idFor('quests',parts[1]));
 }else if(parts[0]==='raid'&&parts[1])params.set('map',parts[1]);
 else if(parts[0]==='documents'&&parts[1])params.set('map',parts[1]);
 else if(parts[0]==='boss'&&parts[1])params.set('boss',parts[1]);
 else if(parts[0]==='quest-map'&&parts[1])params.set('map',parts[1]);
 else if(parts[0]==='reference'&&parts[1]){params.set('tab',parts[1]);params.delete('item');if(parts[1]==='items'&&parts[2])params.set('item',parts[2]);}
 return params;
}
export function canonicalURL(value){
 const url=new URL(value,base()+'/');if(!usesPageRoutes()||url.origin!==base())return url;
 const params=pageParams(url),segment=url.pathname.split('/').filter(Boolean)[0]?.replace(/\.(html|php)$/,'')||'index';
 const take=key=>{const value=params.get(key);params.delete(key);return encodeURIComponent(value||'');};
 let path;
 if(['quest','quests'].includes(segment)){
  if(params.has('quest')){const id=params.get('quest');params.delete('quest');params.delete('trader');params.delete('section');params.delete('tab');path='/quests/'+encodeURIComponent(routes.quests?.[id]?.slug||id);}
  else if(params.has('trader')){const id=params.get('trader');params.delete('trader');params.delete('section');path='/quests/trader/'+encodeURIComponent(routes.traders?.[id]?.slug||id);}
  else path='/quests'+(params.has('section')?'/'+take('section'):'');
 }else if(segment==='raid')path='/raid'+(params.has('map')?'/'+take('map'):'');
 else if(['index','documents'].includes(segment))path=params.has('map')?'/documents/'+take('map'):segment==='documents'||params.has('point')?'/documents':'/';
 else if(segment==='boss')path='/boss'+(params.has('boss')?'/'+take('boss'):'');
 else if(segment==='reference'){
  if(params.has('item')){path='/reference/items/'+take('item');params.delete('tab');}
  else path='/reference'+(params.has('tab')?'/'+take('tab'):'');
 }else if(['quest-map-source','quest-map'].includes(segment))path='/quest-map'+(params.has('map')?'/'+take('map'):'');
 if(segment==='account')path='/account';
 if(path){url.pathname=path;url.search=params.toString();}
 return url;
}
export function siteHref(value){if(!usesPageRoutes())return String(value);const url=canonicalURL(value);return url.origin===base()?url.pathname+url.search+url.hash:url.href;}
export async function initializeSiteRoutes(){
 if(!usesPageRoutes())return;
 const data=document.getElementById('wiki-routes');
 if(data)configureSiteRoutes(JSON.parse(data.textContent));
 else {
  const response=await fetch('/data/navigation-routes.json');
  if(!response.ok)throw new Error('Не удалось загрузить маршруты сайта.');
  configureSiteRoutes(await response.json());
 }
 for(const link of document.querySelectorAll('a[href]')){
  const href=link.getAttribute('href');if(href.startsWith('#'))continue;
  link.setAttribute('href',siteHref(new URL(href,base()+'/')));
 }
 const url=canonicalURL(globalThis.location.href);if(url.href!==globalThis.location.href)history.replaceState(null,'',url);
}
