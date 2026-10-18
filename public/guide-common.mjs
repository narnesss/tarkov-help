import {readPersonal,writePersonal} from './profile-store.mjs?v=loading-20261018';
import {ruText,localizeDisplay} from './ru-data.mjs?v=page-content-20261017';
import {publicLinkURL,PUBLIC_HELP,PUBLIC_WIKI} from './public-links.mjs?v=page-content-20261017';
import {contentResponse} from './content-client.mjs?v=loading-20261018';
import {createJsonPreloader} from './json-preload.mjs?v=page-content-20261017';
export const $=id=>document.getElementById(id);
export const el=(tag,text='',className='')=>{const n=document.createElement(tag);n.textContent=ruText(text);n.className=className;return n;};
export const num=value=>Number(value||0).toLocaleString('ru-RU',{maximumFractionDigits:2});
export const escapeHtml=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
export function safeURL(value){try{const u=new URL(value,globalThis.document?.baseURI||location.href);return ['http:','https:'].includes(u.protocol)?u.href:'';}catch{return '';}}
export function external(label,url){const href=publicLinkURL(url);if(!href)return document.createDocumentFragment();const raw=safeURL(url);if(href!==raw)label=href===PUBLIC_WIKI?'Русская Wiki ↗':href===PUBLIC_HELP?'Tarkov.help ↗':label;const a=el('a',label,'guide-link');a.href=href;a.target='_blank';a.rel='noopener noreferrer';return a;}
export function image(url,alt='',className='',fallback=''){const i=el('img','',className),source=safeURL(url),original=fallback?safeURL(fallback):'';i.alt=alt;i.loading='lazy';i.onerror=()=>{if(original&&i.src!==original){i.src=original;return;}i.hidden=true;};i.src=source;return i;}
let compressionManifest;
const preloaded=createJsonPreloader(loadJson);
export function preloadJson(url){
  if(/^data\/(?:guide\/(?:quests|reference)-(?:pve|regular)|raid\/(?:pve\/)?[a-z-]+)\.json$/.test(url))preloaded.preload(url);
}
export async function json(url,signal){
  const pending=!signal&&preloaded.take(url);return pending||loadJson(url,signal);
}
async function loadJson(url,signal){
  const content=await contentResponse(url,signal);if(content)return content.json();
  let target=url;
  if(url.startsWith('data/')){compressionManifest ||= fetch('data/compressed.json').then(r=>r.ok?r.json():{}).catch(()=>({}));target=(await compressionManifest)[url]||url;}
  const r=await fetch(target,{signal});if(!r.ok)throw new Error(`Не удалось загрузить данные (${r.status})`);
  if(!target.split(/[?#]/,1)[0].endsWith('.gz'))return r.json();
  const bytes=new Uint8Array(await r.arrayBuffer());
  // Some static hosts decode gzip in transit; detect the magic bytes to avoid a second decode.
  if(bytes[0]!==31||bytes[1]!==139)return JSON.parse(new TextDecoder().decode(bytes));
  if(globalThis.DecompressionStream){const stream=new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'));return JSON.parse(await new Response(stream).text());}
  const fallback=await import('./vendor/pako-inflate.mjs?v=page-content-20261017');const pako=fallback.default||globalThis.pako||globalThis.window?.pako;
  return JSON.parse(pako.inflate(bytes,{to:'string'}));
}
export const readStorage=readPersonal;
export const writeStorage=writePersonal;
let timer;export function toast(message){const n=$('guide-toast');if(!n)return;clearTimeout(timer);n.textContent=message;n.hidden=false;timer=setTimeout(()=>n.hidden=true,5000);}
export function download(value,name){const url=URL.createObjectURL(new Blob([JSON.stringify(value,null,2)],{type:'application/json'}));const a=el('a');a.href=url;a.download=name;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);}
export async function copyLink(){try{await navigator.clipboard.writeText(location.href);toast('Ссылка скопирована');}catch{window.prompt('Скопируйте ссылку',location.href);}}
export function localize(value,dictionary){if(Array.isArray(value))return value.map(x=>localize(x,dictionary));if(value&&typeof value==='object')return Object.fromEntries(Object.entries(value).map(([k,v])=>[k,['id','task','item','trader','map','station','objective','normalizedName'].includes(k)?v:localize(v,dictionary)]));return typeof value==='string'&&typeof dictionary[value]==='string'?dictionary[value]:value;}
export function translateApi(raw,dictionary){
  const children=v=>v&&typeof v==='object'?Object.entries(v):[];
  const select=(value,part)=>{
    if(part.kind==='deep')return children(value).flatMap(([k,v])=>[...(k===part.key?[[value,k]]:[]),...select(v,part)]);
    if(part.key==='*')return children(value).map(([k])=>[value,k]);
    return (Array.isArray(part.key)?part.key:[part.key]).filter(k=>value&&typeof value==='object'&&Object.hasOwn(value,k)).map(k=>[value,k]);
  };
  const paths=[...(raw.translations||[]),'$.data.fleaMarket.name','$.data.armorMaterials.*.name'];
  for(const reward of ['startRewards','finishRewards','failureOutcome'])paths.push(`$.data.tasks.*.${reward}.customization[*].name`,`$.data.tasks.*.${reward}.customization[*].customizationTypeName`);
  for(const path of paths){
    let position=1,parts=[];
    while(position<path.length){const rest=path.slice(position);let m;if((m=rest.match(/^\.\.([\w]+)/)))parts.push({kind:'deep',key:m[1]});else if((m=rest.match(/^\.([\w]+|\*)/)))parts.push({kind:'key',key:m[1]});else if((m=rest.match(/^\[([^\]]+)\]/))){const keys=m[1]==='*'?'*':[...m[1].matchAll(/'([^']+)'/g)].map(x=>x[1]);parts.push({kind:'key',key:keys});}else throw new Error('Изменился формат переводов API');position+=m[0].length;}
    let current=[[{root:raw},'root']];for(const part of parts)current=current.flatMap(([parent,key])=>select(parent[key],part));
    for(const [parent,key] of current){const value=parent[key];if(typeof value==='string'&&typeof dictionary[value]==='string')parent[key]=dictionary[value];}
  }return raw.data;
}
export async function apiDataset(kind,mode='pve',signal){const base=`https://json.tarkov.dev/${mode==='pve'?'pve':'regular'}/`;const [raw,locale]=await Promise.all([json(base+kind,signal),json(base+kind+'_ru',signal).catch(()=>({data:{}}))]);if(!raw?.data||raw.errors||(raw.translations?.length&&!Object.keys(locale.data||{}).length))throw new Error('API вернул неполный ответ');return localizeDisplay(translateApi(raw,locale.data||{}));}
function openCache(){return new Promise((resolve,reject)=>{if(!globalThis.indexedDB)return reject(new Error('Cache unavailable'));const r=indexedDB.open('kord-atlas-api',1);r.onupgradeneeded=()=>r.result.createObjectStore('data');r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error);});}
export async function cached(key,value){let db;try{db=await openCache();return await new Promise((resolve,reject)=>{const tx=db.transaction('data',value===undefined?'readonly':'readwrite');const req=value===undefined?tx.objectStore('data').get(key):tx.objectStore('data').put(value,key);req.onsuccess=()=>resolve(req.result);req.onerror=()=>reject(req.error);});}catch{return undefined;}finally{db?.close();}}
export async function freshDataset(key,url,signal){return json(url,signal);}
export function section(title){const s=el('section','','guide-section');s.append(el('h3',title));return s;}
export function action(label,callback,className='button'){const b=el('button',label,className);b.type='button';b.onclick=callback;return b;}
export function empty(message){return el('p',message,'guide-empty');}
