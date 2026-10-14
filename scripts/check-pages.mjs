import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {gunzipSync} from 'node:zlib';
import {root,routePlan} from './build-pages.mjs';
const site=path.join(root,'dist');
const manifest=routePlan();
const index=JSON.parse(fs.readFileSync(path.join(site,'data/navigation-routes.json')));
const location=new URL('https://tarkov-help.pages.dev/');
const anchors=['raid.html?map=interchange&mode=pve','quests.html?quest=68da33fe00868edcb6025ac4&mode=pve',
               'reference.html?tab=items&item=5447a9cd4bdc2dbd208b4567&mode=pve',
               'https://tarkov.help/ru','#quest-objectives-section'].map(href=>({href,getAttribute(){return this.href;},setAttribute(name,value){this.href=value;}}));
globalThis.location=location;
globalThis.document={querySelector(selector){return {content:selector.includes('wiki-runtime')?'static':'pages'};},
                     getElementById(){return null;},querySelectorAll(){return anchors;}};
globalThis.history={replaceState(_state,_title,value){globalThis.location=new URL(value,location.origin);}};
globalThis.fetch=async url=>{assert.equal(url,'/data/navigation-routes.json');return {ok:true,json:async()=>index};};
const routing=await import('../dist/site-routes.mjs');
assert.equal(routing.isPHP(),false);
assert.equal(routing.usesPageRoutes(),true);
await routing.initializeSiteRoutes();
assert.equal(anchors[0].href,'/raid/interchange?mode=pve');
assert.equal(anchors[1].href,'/quests/the-ticket?mode=pve');
assert.equal(anchors[2].href,'/reference/items/5447a9cd4bdc2dbd208b4567?mode=pve');
assert.equal(anchors[3].href,'https://tarkov.help/ru');
assert.equal(anchors[4].href,'#quest-objectives-section');
let routeCount=0;
for(const [url,entry] of Object.entries(manifest)){
 const filename=path.join(site,url.slice(1)+'.html');
 const source=fs.readFileSync(filename,'utf8');
 assert.match(source,/<base href="\/">/);
 assert.match(source,/<meta name="wiki-routing" content="pages">/);
 assert.doesNotMatch(source,/<\?php|wiki-runtime" content="php"/);
 const params=routing.pageParams(url+'?mode=pve&flag=kappa&floor=all');
 for(const [key,value] of Object.entries(entry.params))assert.equal(params.get(key),value,url+' '+key);
 for(const [key,value] of [['mode','pve'],['flag','kappa'],['floor','all']])assert.equal(params.get(key),value,url+' filter '+key);
 assert.equal(routing.pageParams(url+'.html?mode=regular').get('mode'),'regular');
 const canonical=routing.canonicalURL(url+'?mode=pve&flag=kappa');
 const canonicalFile=path.join(site,canonical.pathname.slice(1)+'.html');
 assert.ok(fs.existsSync(canonicalFile),canonical.href);
 assert.equal(canonical.searchParams.get('mode'),'pve');
 routeCount++;
}
const legacy=[['/quest.html?quest=68da33fe00868edcb6025ac4&mode=pve&tab=objectives','/quests/the-ticket?mode=pve'],
 ['/quests.php?trader=54cb50c76803fa8b248b4571&mode=regular&flag=kappa','/quests/trader/prapor?mode=regular&flag=kappa'],
 ['/raid.html?map=shoreline&quest=5eda19f0edce541157209cee&objective=5eda1d6ec586607c09662d54&mode=pve','/raid/shoreline?quest=5eda19f0edce541157209cee&objective=5eda1d6ec586607c09662d54&mode=pve'],
 ['/documents.html?map=customs&point=abc','/documents/customs?point=abc'],
 ['/reference.html?tab=crafts&mode=pve','/reference/crafts?mode=pve'],
 ['/boss.html?boss=reshala&mode=pve','/boss/reshala?mode=pve'],
 ['/quest-map-source.html?map=customs&quest=abc&view=frame&mode=pve','/quest-map/customs?quest=abc&view=frame&mode=pve']];
for(const [input,expected] of legacy)assert.equal(routing.siteHref(input),expected,input);
// Changing a map/section from an existing deep page must update its physical path.
assert.equal(routing.siteHref('/raid.html?map=woods&floor=all&mode=regular'),'/raid/woods?floor=all&mode=regular');
assert.equal(routing.siteHref('/reference.html?tab=barters&mode=regular'),'/reference/barters?mode=regular');
let files=[];
function walk(dir){for(const entry of fs.readdirSync(dir,{withFileTypes:true})){
 const filename=path.join(dir,entry.name);if(entry.isDirectory())walk(filename);else files.push(filename);
}}
walk(site);
assert.ok(files.length<=20000);
for(const filename of files){
 assert.ok(fs.statSync(filename).size<=25*1024*1024,filename);
 assert.ok(!filename.endsWith('.php'),filename);
 if(filename.endsWith('.json.gz'))JSON.parse(gunzipSync(fs.readFileSync(filename)));
 else if(filename.endsWith('.json'))JSON.parse(fs.readFileSync(filename));
}
const compressed=JSON.parse(fs.readFileSync(path.join(site,'data/compressed.json')));
for(const [source,target] of Object.entries(compressed))assert.ok(fs.existsSync(path.join(site,target)),source);
for(const filename of files.filter(name=>name.endsWith('.html'))){
 const source=fs.readFileSync(filename,'utf8');
 for(const match of source.matchAll(/(?:src|href)="([^"#]+)"/g)){
  const href=match[1];if(/^(?:https?:|data:|mailto:)/.test(href))continue;
  const url=routing.canonicalURL(href.replaceAll('&amp;','&'));
  let target=path.join(site,url.pathname.slice(1));
  if(url.pathname==='/')target=path.join(site,'index.html');
  else if(!fs.existsSync(target)&&!path.extname(target))target+='.html';
  assert.ok(fs.existsSync(target),filename+' -> '+href);
 }
}
assert.ok(fs.existsSync(path.join(site,'404.html')));
assert.ok(!fs.existsSync(path.join(site,'DEPLOY-NETLIFY.txt')));
// Browser profiles do not try to call the unavailable PHP API.
const {ProfileStore}=await import('../dist/profile-store.mjs');
let requests=0;const storage=new Map();
const browserStorage={getItem:key=>storage.get(key)??null,setItem:(key,value)=>storage.set(key,value),get length(){return storage.size;},key:i=>[...storage.keys()][i]};
const profile=new ProfileStore({storage:browserStorage,request:async()=>{requests++;throw Error('PHP API must not be used');}});
await profile.initialize('static');
profile.write('kord-atlas-game-mode','pve');
assert.equal(profile.read('kord-atlas-game-mode'),'pve');
assert.equal(await profile.flush(),true);assert.equal(requests,0);
console.log(JSON.stringify({checked_routes:routeCount,checked_files:files.length,legacy_links:legacy.length,profile_api_requests:requests,status:'passed'}));
