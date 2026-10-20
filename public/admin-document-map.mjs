import {documentLevelSvg,documentViewport} from './document-map.mjs?v=page-content-20261017';

export function mapPercent(x,y,rect){
 if(![x,y,rect.left,rect.top,rect.width,rect.height].every(Number.isFinite)||rect.width<=0||rect.height<=0)return null;
 const point={x:(x-rect.left)/rect.width*100,y:(y-rect.top)/rect.height*100};
 return validCoordinates(point)?point:null;
}
export function validCoordinates(point){return !!point&&[point.x,point.y].every(n=>typeof n==='number'&&Number.isFinite(n)&&n>=0&&n<=100);}
export function pointKey(point){return `${point.kind}:${point.pointId??'new'}:${point.positionId??0}`;}

const root=globalThis.document?.getElementById('document-map-editor');
if(root)initialise(root);

function initialise(root){
 const $=id=>root.querySelector('#dme-'+id),state={snapshot:null,svg:'',draft:null,dirty:false,placing:false,epoch:0,zoom:1,url:null,busy:false};
 let controller;
 const status=text=>{$('status').textContent=text;},error=text=>{$('error').textContent=text;$('error').hidden=!text;};
 const floorName=floor=>'Уровень схемы '+floor.replace('floor-','').replace('-','−');
 const selectedFloor=()=> $('floor').value;
 const option=(value,text)=>{const o=document.createElement('option');o.value=value;o.textContent=text;return o;};
 const discard=()=>!state.dirty||confirm('Есть несохранённое положение точки. Отменить эту правку?');
 const endpoint=(name)=>{const url=new URL(root.dataset[name],location.href);url.searchParams.set('map',$('map').value);url.searchParams.set('mode',$('mode').value);return url;};
 function setBusy(value){state.busy=value;$('save').disabled=value||!state.dirty;for(const name of ['reload','map','mode','floor','add-document','add-exit'])$(name).disabled=value;}
 function clearErrors(){error('');for(const field of ['x','y','floor','title','documentId']){const input=$(field==='floor'?'point-floor':field==='documentId'?'document':field),message=$(field+'-error');input?.classList.remove('is-invalid');input?.removeAttribute('aria-invalid');if(message)message.hidden=true;}}
 function fieldError(field,text){const input=$(field==='floor'?'point-floor':field==='documentId'?'document':field),message=$(field+'-error');if(input){input.classList.add('is-invalid');input.setAttribute('aria-invalid','true');}if(message){message.textContent=text;message.hidden=false;}}
 async function request(url,options={}){
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options,signal:options.signal??AbortSignal.timeout(25000),headers:{Accept:'application/json',...options.headers}});
  const data=await response.json().catch(()=>null);
  if(!response.ok){const failure=new Error(response.status>=500?'Сервер временно недоступен. Правка остаётся в форме.':data?.message||'Не удалось выполнить запрос. Обновите вход в админку и повторите.');failure.status=response.status;failure.fields=data?.errors||{};throw failure;}
  return data;
 }
 async function load(keepDraft=false){
  const epoch=++state.epoch;controller?.abort();controller=new AbortController();setBusy(true);error('');status('Загрузка карты…');
  const previous=keepDraft?state.draft:null,previousFloor=selectedFloor();
  try{
   const signal=AbortSignal.any([controller.signal,AbortSignal.timeout(25000)]);
   const [snapshot,response]=await Promise.all([request(endpoint('read'),{signal}),fetch(endpoint('artwork'),{credentials:'same-origin',cache:'no-store',signal})]);
   if(!response.ok)throw Error('Не удалось загрузить схему. Нажмите «Обновить карту», чтобы повторить.');
   const svg=await response.text();if(epoch!==state.epoch)return;
   state.snapshot=snapshot;state.svg=svg;state.draft=previous;state.dirty=!!previous;state.placing=false;
   $('floor').replaceChildren(...snapshot.floors.map(f=>option(f,floorName(f))));
   $('point-floor').replaceChildren(...snapshot.floors.map(f=>option(f,floorName(f))));
   $('document').replaceChildren(option('','Выберите документ'),...snapshot.documents.map(d=>option(d.id,d.name)));
   const requested=previous?.floor||previousFloor||new URLSearchParams(location.search).get('floor');
   $('floor').value=snapshot.floors.includes(requested)?requested:(snapshot.floors.includes('floor-1')?'floor-1':snapshot.floors[0]);
   if(previous?.pointId&&!snapshot.points.some(p=>pointKey(p)===pointKey(previous))){state.draft=null;state.dirty=false;error('Точка больше не доступна. Несохранённое положение: X '+previous.x+', Y '+previous.y+'. Обновите список.');}
   $('rebase').hidden=true;render();status(previous?'Свежая карта загружена. Проверьте оставленную правку и снова нажмите «Сохранить положение».':'Карта загружена. Выберите точку или добавьте новую.');
  }catch(failure){if(epoch===state.epoch){error(failure.message);status('Карта не загружена. Повторите загрузку.');}}
  finally{if(epoch===state.epoch)setBusy(false);}
 }
 function renderImage(){
  if(!state.snapshot)return;
  try{const svg=documentLevelSvg(state.svg,state.snapshot.map,selectedFloor());
   if(state.url)URL.revokeObjectURL(state.url);state.url=URL.createObjectURL(new Blob([svg],{type:'image/svg+xml'}));$('image').src=state.url;
   const size=documentViewport(state.snapshot.map);$('plane').style.aspectRatio=`${size.width}/${size.height}`;$('plane').style.width=(state.zoom*100)+'%';
  }catch{error('Не удалось прочитать этаж схемы. Повторите загрузку.');}
 }
 function renderForm(){
  const draft=state.draft;$('form').hidden=!draft;if(!draft)return;
  $('selected').textContent=draft.pointId?draft.name:(draft.kind==='document'?'Новый документ':'Новый выход ЧВК');
  $('new-fields').hidden=!!draft.pointId;$('document-label').hidden=draft.kind!=='document';
  $('title').value=draft.title||'';$('document').value=draft.documentId||'';$('point-floor').value=draft.floor;
  $('x').value=Number.isFinite(draft.x)?Number(draft.x.toFixed(4)):'';$('y').value=Number.isFinite(draft.y)?Number(draft.y.toFixed(4)):'';
  $('details').hidden=!draft.pointId;if(draft.pointId)$('details').href='/admin/wiki/map_points/'+draft.pointId+'/edit';
  $('save').disabled=state.busy||!state.dirty;$('stage').classList.toggle('is-placing',state.placing);
  $('place').textContent=state.placing?'Нажмите на нужное место схемы':'Указать место на схеме';
 }
 function renderList(){
  if(!state.snapshot)return;const query=$('search').value.trim().toLocaleLowerCase(),kind=$('kind-filter').value;
  const rows=state.snapshot.points.filter(p=>(kind==='all'||p.kind===kind)&&p.name.toLocaleLowerCase().includes(query));
  $('points').replaceChildren();
  if(!rows.length){const p=document.createElement('p');p.textContent='Подходящих точек нет.';$('points').append(p);}
  for(const point of rows){const button=document.createElement('button');button.type='button';button.textContent=(point.kind==='exit'?'↗ ':'● ')+point.name+(point.order?' · позиция '+(point.order+1):'')+' · '+(point.floor?floorName(point.floor):'не размещён')+(point.kind==='exit'&&!point.independent?' · автопривязка':'');
   button.setAttribute('aria-pressed',String(state.draft&&pointKey(state.draft)===pointKey(point)||false));button.onclick=()=>select(point);$('points').append(button);}
 }
 function renderMarkers(){
  if(!state.snapshot)return;$('markers').replaceChildren();
  const rows=state.snapshot.points.filter(p=>!state.draft||pointKey(p)!==pointKey(state.draft));if(state.draft)rows.push(state.draft);
  for(const point of rows){if(point.floor!==selectedFloor()||!validCoordinates(point))continue;
   const button=document.createElement('button');button.type='button';button.className='dme-marker';button.dataset.kind=point.kind;button.style.left=point.x+'%';button.style.top=point.y+'%';
   button.setAttribute('aria-label',(point.kind==='exit'?'Выход ЧВК: ':'Документ: ')+(point.name||point.title||'Новая точка'));button.title=button.getAttribute('aria-label');
   button.setAttribute('aria-pressed',String(state.draft&&pointKey(state.draft)===pointKey(point)||false));const symbol=document.createElement('span');symbol.textContent=point.kind==='exit'?'↗':'●';button.append(symbol);
   button.addEventListener('pointerdown',event=>{
    if(state.busy||!select(point,false))return;event.preventDefault();event.stopPropagation();button.setPointerCapture(event.pointerId);let moved=false;
    const move=e=>{const position=mapPercent(e.clientX,e.clientY,$('plane').getBoundingClientRect());if(!position)return;moved=true;Object.assign(state.draft,position);state.dirty=true;button.style.left=position.x+'%';button.style.top=position.y+'%';renderForm();};
    const end=()=>{button.removeEventListener('pointermove',move);button.removeEventListener('pointerup',end);button.removeEventListener('pointercancel',end);renderList();if(moved)status('Положение изменено. Нажмите «Сохранить положение».');};
    button.addEventListener('pointermove',move);button.addEventListener('pointerup',end);button.addEventListener('pointercancel',end);
   });
   button.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key)||state.busy)return;if(!select(point,false))return;event.preventDefault();const step=event.shiftKey?.1:1;const x=state.draft.x+(event.key==='ArrowLeft'?-step:event.key==='ArrowRight'?step:0),y=state.draft.y+(event.key==='ArrowUp'?-step:event.key==='ArrowDown'?step:0);if(validCoordinates({x,y})){Object.assign(state.draft,{x,y});state.dirty=true;button.style.left=x+'%';button.style.top=y+'%';renderForm();}});
   button.onclick=()=>{if(!state.draft||pointKey(state.draft)!==pointKey(point))select(point);};$('markers').append(button);
  }
 }
 function render(){renderImage();renderList();renderForm();renderMarkers();}
 function select(point,redraw=true){
  if(state.busy)return false;
  if(state.draft&&pointKey(state.draft)===pointKey(point))return true;
  if(!discard())return false;clearErrors();state.draft={...point};state.dirty=false;state.placing=false;
  if(state.snapshot.floors.includes(point.floor))$('floor').value=point.floor;else state.draft.floor=selectedFloor();
  if(redraw)render();else{renderForm();renderList();}return true;
 }
 function add(kind){if(state.busy||!state.snapshot||!discard())return;clearErrors();state.draft={kind,pointId:null,positionId:null,title:'',name:'',floor:selectedFloor(),x:null,y:null};state.dirty=true;state.placing=true;render();$('title').focus();status('Введите название, выберите документ при необходимости и укажите место на схеме.');}
 $('add-document').onclick=()=>add('document');$('add-exit').onclick=()=>add('exit');
 $('place').onclick=()=>{if(state.busy||!state.draft)return;state.placing=!state.placing;renderForm();status(state.placing?'Нажмите на нужное место схемы.':'Установка отменена.');};
 $('plane').onclick=event=>{if(!state.placing||!state.draft||event.target.closest('button'))return;const point=mapPercent(event.clientX,event.clientY,$('plane').getBoundingClientRect());if(!point)return;Object.assign(state.draft,point,{floor:selectedFloor()});state.dirty=true;state.placing=false;renderForm();renderMarkers();status('Место выбрано. Нажмите «Сохранить положение».');};
 for(const axis of ['x','y'])$(axis).oninput=()=>{if(!state.draft)return;state.draft[axis]=$(axis).value===''?null:Number($(axis).value);state.dirty=true;$('save').disabled=state.busy;renderMarkers();};
 $('title').oninput=()=>{if(state.draft){state.draft.title=$('title').value;state.dirty=true;}};
 $('document').onchange=()=>{if(state.draft){state.draft.documentId=$('document').value?Number($('document').value):null;state.dirty=true;}};
 $('point-floor').onchange=()=>{if(state.draft){state.draft.floor=$('point-floor').value;$('floor').value=state.draft.floor;state.dirty=true;render();}};
 $('floor').onchange=()=>{if(!discard()){$('floor').value=state.draft.floor;return;}state.draft=null;state.dirty=false;state.placing=false;render();};
 for(const name of ['map','mode'])$(name).onchange=()=>{if(!discard()){$('map').value=state.snapshot.map.id;$('mode').value=state.snapshot.mode;return;}state.draft=null;state.dirty=false;state.zoom=1;load();};
 $('search').oninput=renderList;$('kind-filter').onchange=renderList;
 $('cancel').onclick=()=>{state.draft=null;state.dirty=false;state.placing=false;clearErrors();render();status('Несохранённая правка отменена.');};
 $('reload').onclick=()=>{if(discard()){state.draft=null;state.dirty=false;load();}};
 $('rebase').onclick=()=>load(true);
 $('save').onclick=async()=>{
  if(!state.draft||state.busy||!state.dirty)return;clearErrors();const draft=state.draft;
  if(!validCoordinates(draft)){for(const axis of ['x','y'])if(draft[axis]===null||!Number.isFinite(draft[axis])||draft[axis]<0||draft[axis]>100)fieldError(axis,'Укажите координату от 0 до 100 или выберите место на схеме.');return;}
  if(!draft.pointId&&(!draft.title?.trim()||/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/.test(draft.title)||draft.title.length>255)){fieldError('title','Введите обычный текст до 255 символов.');return;}
  if(!draft.pointId&&draft.kind==='document'&&!draft.documentId){fieldError('documentId','Выберите документ по названию.');return;}
  const epoch=state.epoch,payload={version:state.snapshot.version,kind:draft.kind,pointId:draft.pointId,positionId:draft.positionId??null,floor:draft.floor,x:draft.x,y:draft.y};
  if(!draft.pointId){payload.title=draft.title.trim();if(draft.kind==='document')payload.documentId=draft.documentId;}
  setBusy(true);status('Сохранение…');
  try{const result=await request(endpoint('save'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':$('token').value},body:JSON.stringify(payload)});
   if(epoch!==state.epoch)return;state.snapshot=result.snapshot;state.draft=result.snapshot.points.find(p=>p.pointId===result.pointId&&(p.positionId===draft.positionId||!draft.positionId))||null;state.dirty=false;state.placing=false;render();status('Положение сохранено. Изменение доступно на сайте без очистки кэша.');
  }catch(failure){if(epoch!==state.epoch)return;error(failure.message);for(const [field,messages]of Object.entries(failure.fields||{}))fieldError(field,messages[0]);if(failure.status===409)$('rebase').hidden=false;status('Сохранение не подтверждено. Правка остаётся в форме.');}
  finally{if(epoch===state.epoch)setBusy(false);}
 };
 for(const [name,factor]of [['zoom-in',1.5],['zoom-out',1/1.5]])$(name).onclick=()=>{state.zoom=Math.max(1,Math.min(6,state.zoom*factor));$('plane').style.width=state.zoom*100+'%';$('zoom').textContent=Math.round(state.zoom*100)+'%';};
 window.addEventListener('beforeunload',event=>{if(state.dirty){event.preventDefault();event.returnValue='';}});
 document.addEventListener('turbo:before-visit',event=>{if(!discard())event.preventDefault();});
 const query=new URLSearchParams(location.search);if([...$('map').options].some(o=>o.value===query.get('map')))$('map').value=query.get('map');if(['pve','regular'].includes(query.get('mode')))$('mode').value=query.get('mode');
 load();
}
