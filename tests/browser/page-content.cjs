const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('fs'),path=require('path'),zlib=require('zlib'),assert=require('node:assert/strict');
const source=path.resolve(__dirname,'../../public/data/guide'),base=process.env.TEST_BASE_URL||'http://localhost:8111';
function read(name){const file=path.join(source,name+'.json');return JSON.parse(fs.existsSync(file)?fs.readFileSync(file,'utf8'):zlib.gunzipSync(fs.readFileSync(file+'.gz')));}
const quests=read('quests-pve'),task=read('catalog-update').modes.pve.tasks.find(t=>t.normalizedName==='boreas');assert.ok(task,'Boreas exists');
const boss=read('bosses'),profile=boss.profiles.find(b=>b.slug==='birdeye');assert.ok(profile);
const reference=read('reference-pve'),items=read('items-pve'),item=items.items.find(i=>i.id==='5447a9cd4bdc2dbd208b4567');assert.ok(item);
const emptyQuests={cms:true,tasks:[],questItems:[],maps:[]};
const questDetail={...quests,partial:true,cms:true,tasks:[task],contextTasks:[],ui:{questId:task.id,uiReady:true,choices:{},questMaps:{modes:{}},ticketMap:{},conditions:{},rewardSupport:{}}};
const bossDetail={profiles:[profile],reference:{meta:reference.meta,maps:reference.maps.filter(m=>(profile.canonicalMapSlugs||[]).includes(m.normalizedName)||(m.bosses||[]).some(b=>b.mob===(profile.spawnParentMobId||profile.id))).map(m=>({...m,bosses:(m.bosses||[]).filter(b=>b.mob===(profile.spawnParentMobId||profile.id))}))}};
const itemDetail={...items,items:[item],catalog:false,related:{reference:{meta:reference.meta,traders:reference.traders,crafts:[],barters:[]},quests:emptyQuests}};
(async()=>{
 const browser=await chromium.launch({...(process.env.BROWSER_EXECUTABLE?{executablePath:process.env.BROWSER_EXECUTABLE}:{}),headless:true,args:['--disable-background-networking']});
 try{
  const results=[];
  for(const [route,payload,heading,absent] of [
   ['/quests/boreas?mode=pve',questDetail,'.quest-hero h1','#quest-search'],
   ['/boss/birdeye?mode=pve',bossDetail,'#boss-title','#boss-search'],
   ['/reference/items/'+item.id+'?mode=pve',itemDetail,'#reference-title','#reference-search'],
  ]){
   const context=await browser.newContext({viewport:{width:1366,height:900}}),page=await context.newPage(),errors=[],requests=[];
   page.on('pageerror',error=>errors.push(error.message));
   let release;const gate=new Promise(resolve=>release=resolve);
   await page.route('**/api/content/**',async handler=>{const url=new URL(handler.request().url());if(url.pathname==='/api/content/time')return handler.fulfill({status:503,json:{error:'Часы недоступны в изолированном тесте'}});requests.push(url.pathname+url.search);await gate;await handler.fulfill({json:payload});});
   await page.route('https://**/*',handler=>handler.fulfill({status:404,body:''}));
   await page.goto(base+route,{waitUntil:'commit'});
   await page.locator('#page-boot-status').waitFor({state:'visible'});
   await page.waitForFunction(()=>document.readyState!=='loading');
   assert.equal(await page.locator('body').getAttribute('data-content-state'),'loading');
   assert.equal(await page.locator('main').isVisible(),false,'Во время задержки каталог не виден');
   assert.equal(await page.locator(absent).count(),0,'HTML каталога отсутствует в карточке');
   release();await page.waitForFunction(()=>document.body.dataset.contentState==='ready',{timeout:10000});
   assert.ok((await page.locator(heading).innerText()).length>1);
   assert.equal(await page.locator('main').isVisible(),true);
   assert.deepEqual(errors,[]);
   assert.ok(requests.length>=1);assert.ok(requests.every(url=>url.includes('view=detail')),'Только detail запросы: '+requests);
   if(route.startsWith('/quests')){
    const saved=await page.evaluate(async()=>{
     const {readPersonal,writePersonal}=await import('/profile-store.mjs?v=page-content-20261017');const key='kord-atlas-quests-pve-v1';
     const state=readPersonal(key)||{version:1,mode:'pve',level:1,faction:'any',quests:{}};state.quests.other={status:'complete',objectives:['old-objective'],storyBranch:'my-branch',note:'сохранить',favorite:true};writePersonal(key,state);
     dispatchEvent(new CustomEvent('wiki:state',{detail:{key}}));return state.quests.other;
    });
    await page.locator('#selected-complete').click();
    const after=await page.evaluate(async()=>{const {readPersonal}=await import('/profile-store.mjs?v=page-content-20261017');return readPersonal('kord-atlas-quests-pve-v1').quests.other;});assert.deepEqual(after,saved);
   }
   results.push({route,title:await page.locator(heading).innerText(),requests,errors});await context.close();
  }
  const searchContext=await browser.newContext(),searchPage=await searchContext.newPage(),searchRequests=[];
  await searchPage.route('**/api/content/**',async handler=>{
   const url=handler.request().url();searchRequests.push(url);
   await handler.fulfill({json:url.includes('home.json')?read('../home'):{items:[{id:item.id,name:'Уникальное имя из backend'}]}});
  });
  await searchPage.goto(base+'/');await searchPage.waitForFunction(()=>document.body.dataset.contentState==='ready');
  await searchPage.locator('#home-search').fill('Уникальное');await searchPage.getByText('Уникальное имя из backend',{exact:true}).waitFor({state:'visible'});
  assert.ok(searchRequests.some(url=>url.includes('items-pve.json?view=lookup')));results.push({search:'Новые имена в поиске берутся из backend'});await searchContext.close();
  const context=await browser.newContext(),page=await context.newPage(),requests=[];
  await page.route('**/api/content/**',async handler=>{requests.push(handler.request().url());await handler.fulfill({status:503,json:{error:'Unavailable'}});});
  await page.goto(base+'/quests/boreas?mode=pve');
  await page.waitForFunction(()=>document.body.dataset.contentState==='error');
  assert.equal(await page.locator('main').isVisible(),false);assert.ok((await page.locator('#page-boot-status').innerText()).includes('загрузить'));
  assert.ok(requests.every(url=>url.includes('/api/content/')));results.push({errorCase:'API 503: каталог скрыт, доступна кнопка Повторить'});
  await context.close();console.log(JSON.stringify(results,null,2));
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
