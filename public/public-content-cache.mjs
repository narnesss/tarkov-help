// Только опубликованные справочники; здесь никогда не бывает профилей, cookies или CSRF.
const limit=32*1024*1024;
function database(){return new Promise((resolve,reject)=>{const open=indexedDB.open('kord-public-content-v1',1);open.onupgradeneeded=()=>open.result.createObjectStore('files',{keyPath:'key'});open.onsuccess=()=>resolve(open.result);open.onerror=()=>reject(open.error);});}
export const publicContentCache={
 get available(){return typeof indexedDB!=='undefined';},
 async get(key){let db;try{db=await database();return await new Promise((resolve,reject)=>{const get=db.transaction('files').objectStore('files').get(key);get.onsuccess=()=>resolve(get.result);get.onerror=()=>reject(get.error);});}finally{db?.close();}},
 async put(key,entry){
  if(entry.text.length*2>limit/2)return;let db;
  try{db=await database();await new Promise((resolve,reject)=>{
   const tx=db.transaction('files','readwrite'),store=tx.objectStore('files'),all=store.getAll();
   all.onsuccess=()=>{const rows=all.result.filter(row=>row.key!==key).sort((a,b)=>a.at-b.at);let size=rows.reduce((sum,row)=>sum+row.text.length*2,0)+entry.text.length*2;
    while(rows.length&&(rows.length>=23||size>limit)){const old=rows.shift();size-=old.text.length*2;store.delete(old.key);}store.put({key,...entry,at:Date.now()});};
   tx.oncomplete=()=>resolve();tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error);
  });}finally{db?.close();}
 },
};
export async function publicCachedResponse(url,{request=globalThis.fetch,signal,cache=publicContentCache}={}){
 const options={signal,cache:'no-store',credentials:'omit'};
 if(!/^\/api\/content\/(?:data\/(?!wiki\/)[a-z0-9/._-]+\.json|bundle\/(?:quest-extras|raid-base|quest-ui))(?:\?|$)/.test(url)||!cache.available)return request(url,options);
 let saved;try{saved=await cache.get(url);}catch{/* Ошибка IndexedDB не мешает обычной загрузке. */}
 if(saved&&typeof saved.text==='string'&&/^[a-f0-9]{64}$/.test(saved.revision)){
  const check=await request(url+(url.includes('?')?'&':'?')+'revision=1',options);
  if(check.ok){const value=await check.json();if(value.revision===saved.revision)return new Response(saved.text,{headers:{'Content-Type':'application/json','Cache-Control':'private, no-store','X-Wiki-Browser-Cache':'HIT'}});}
 }
 const response=await request(url,options),revision=response.headers.get('X-Wiki-Content-Revision');
 if(response.ok&&/^[a-f0-9]{64}$/.test(revision??'')&&response.headers.get('Content-Type')?.includes('application/json')){
  response.clone().text().then(text=>cache.put(url,{revision,text})).catch(()=>{});
 }
 return response;
}
