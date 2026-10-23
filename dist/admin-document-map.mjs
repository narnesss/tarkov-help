import {documentLevelSvg,documentViewport} from './document-map.mjs';
import {createEditorMap,editorMarkerIcon,editorListButton} from './admin-map-ui.mjs';
import {documentPointKey,documentPoints,documentChange,updateDocumentDraft,discardDocumentDraft,rebaseDocumentDrafts} from './admin-document-drafts.mjs';

export const pointKey=documentPointKey;
export function validCoordinates(point){return !!point&&[point.x,point.y].every(n=>typeof n==='number'&&Number.isFinite(n)&&n>=0&&n<=100);}
export function mapPercent(x,y,rect){
 if(![x,y,rect.left,rect.top,rect.width,rect.height].every(Number.isFinite)||rect.width<=0||rect.height<=0)return null;
 const point={x:(x-rect.left)/rect.width*100,y:(y-rect.top)/rect.height*100};return validCoordinates(point)?point:null;
}
const root=globalThis.document?.getElementById('document-map-editor');if(root)initialise(root);

function initialise(root){
 const $=id=>root.querySelector('#dme-'+id),L=window.L;
 let snapshot,points=[],saved=[],selected,pending=new Map(),conflict,placing=false,busy=false,epoch=0,limit=100,svg='',artwork,url,controller,loadedScope;
 const map=createEditorMap(L,$('canvas'),{crs:L.CRS.Simple,minZoom:-20}),markers=L.layerGroup().addTo(map);
 const option=(value,text)=>{const node=document.createElement('option');node.value=value;node.textContent=text;return node;};
 const status=(text,error=false)=>{$('status').textContent=text;$('status').setAttribute('role',error?'alert':'status');};
 const floor=()=>$('floor').value,dirty=()=>pending.size>0;
 const confirmLoss=()=>!dirty()||window.confirm('Есть несохранённые изменения. Отменить их и сменить карту?');
 const floorName=id=>'Уровень схемы '+id.replace('floor-','').replace('-','−');
 const endpoint=name=>{const address=new URL(root.dataset[name],location.href);address.searchParams.set('map',$('map').value);address.searchParams.set('mode',$('mode').value);if(name==='read')address.searchParams.set('archived','1');return address;};
 const size=()=>documentViewport(snapshot.map),bounds=()=>[[-size().height,0],[0,size().width]];
 const coordinates=point=>[-point.y/100*size().height,point.x/100*size().width];
 const icon=point=>new URL('assets/raid-icons/'+(point.kind==='exit'?'extract_pmc':'quest_objective')+'.png',root.dataset.assets).href;
 const flags=point=>({selected:point.id===selected?.id,dirty:pending.has(point.id),archived:!!point.archived});
 async function request(address,body,signal){
  const response=await fetch(address,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:signal??AbortSignal.timeout(25000),
   headers:{Accept:'application/json',...(body?{'Content-Type':'application/json','X-CSRF-TOKEN':root.dataset.csrf}:{})},...(body?{body:JSON.stringify(body)}:{})});
  const data=await response.json().catch(()=>({message:'Не удалось обработать ответ сервера.'}));
  if(!response.ok)throw Object.assign(Error(response.status>=500?'Сервер временно недоступен. Все правки остаются здесь.':data.message||'Не удалось выполнить запрос.'),{status:response.status,data});return data;
 }
 function controls(){
  $('save').disabled=busy||!dirty()||!!conflict||!!$('fields').querySelector('.is-invalid');$('save').textContent='Сохранить'+(dirty()?' ('+pending.size+')':'');$('discard').disabled=busy||!dirty();
  for(const id of ['map','mode','floor','add','fit','rebase','server'])$(id).disabled=busy||!snapshot;
  for(const input of $('fields').querySelectorAll('input,textarea,select,button'))input.disabled=busy;
  $('discard-point').disabled=busy||!selected||!pending.has(selected.id);$('type').disabled=busy||!!selected?.pointId;
  $('document').disabled=busy||!!selected?.pointId;$('archived').disabled=busy||!snapshot?.capabilities?.restorePoint;
  $('name').disabled=busy||(!!selected?.pointId&&!snapshot?.capabilities?.editPointDetails);$('description').disabled=busy||!snapshot?.capabilities?.editPointDetails;
  $('archive').disabled=busy||!selected?.pointId||!snapshot?.capabilities?.restorePoint;
 }
 function clearErrors(){for(const node of root.querySelectorAll('.me-error'))node.textContent='';for(const node of root.querySelectorAll('.is-invalid')){node.classList.remove('is-invalid');node.removeAttribute('aria-invalid');}}
 function fieldError(field,text){const id={title:'name',floor:'point-floor',documentId:'document',kind:'type'}[field]||field,input=$(id);if(input){input.classList.add('is-invalid');input.setAttribute('aria-invalid','true');}if($(field+'-error'))$(field+'-error').textContent=text;}
 function fields(){
  $('fields').hidden=!selected;$('help').hidden=!!selected;if(!selected){controls();return;}
  $('name').value=selected.name||'';$('description').value=selected.description||'';$('type').value=selected.kind;
  $('document-label').hidden=selected.kind!=='document'||!!selected.pointId;$('document').value=selected.documentId??'';$('point-floor').value=selected.floor??'';
  for(const axis of ['x','y'])$(axis).value=Number.isFinite(selected[axis])?Number(selected[axis].toFixed(5)):'';
  $('archive').textContent=selected.archived?'Восстановить':'В архив';$('details').hidden=!selected.pointId;if(selected.pointId)$('details').href='/admin/wiki/map_points/'+selected.pointId+'/edit';controls();
 }
 function visible(){const query=$('search').value.trim().toLocaleLowerCase('ru');return points.filter(point=>(floor()==='all'||point.floor===floor()||!point.floor)&&($('category').value==='all'||point.kind===$('category').value)&&($('archived').checked||!point.archived)&&(!query||[point.name,point.description,point.externalId].join(' ').toLocaleLowerCase('ru').includes(query)));}
 function select(point){if(busy)return;clearErrors();selected=point;fields();render();}
 function changed(patch,redraw=true){
  if(busy||!selected)return false;
  try{updateDocumentDraft(points,pending,selected,patch);}catch(error){status(error.message,true);fields();return false;}
  clearErrors();controls();if(redraw)render();status('Несохранённых точек: '+pending.size);return true;
 }
 function move(point,ll){selected=point;const position={x:ll.lng/size().width*100,y:-ll.lat/size().height*100};if(!validCoordinates(position)){status('Выберите место в границах схемы.',true);render();return;}changed({...position,floor:floor()==='all'?point.floor:floor()});fields();}
 function render(){
  if(!snapshot)return;markers.clearLayers();$('list').replaceChildren();const list=visible();$('count').textContent='Точек в выбранном слое: '+list.length;
  for(const point of list){if(!validCoordinates(point))continue;const marker=L.marker(coordinates(point),{icon:editorMarkerIcon(L,icon(point),flags(point)),draggable:!busy,keyboard:true,autoPanOnFocus:false,title:point.name,poiId:point.id}).addTo(markers);
   marker.on('click',()=>select(point));marker.on('dragstart',()=>{selected=point;fields();});marker.on('dragend',()=>move(point,marker.getLatLng()));
   const element=marker.getElement();element.setAttribute('aria-label',point.name);element.addEventListener('keydown',event=>{
    if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key)||busy)return;event.preventDefault();event.stopPropagation();selected=point;const step=event.shiftKey?.1:1;
    const position={x:point.x+(event.key==='ArrowLeft'?-step:event.key==='ArrowRight'?step:0),y:point.y+(event.key==='ArrowUp'?-step:event.key==='ArrowDown'?step:0)};
    if(validCoordinates(position)){changed(position);fields();markers.getLayers().find(m=>m.options.poiId===point.id)?.getElement().focus({preventScroll:true});}
   });
  }
  for(const point of list.slice(0,limit)){const label=point.name+(point.order?' · позиция '+(point.order+1):'')+(!validCoordinates(point)?' · не размещён':'');const button=editorListButton(icon(point),label,flags(point));button.onclick=()=>{select(point);if(validCoordinates(point))map.panTo(coordinates(point));};$('list').append(button);}
  if(!list.length){const p=document.createElement('p');p.className='me-empty';p.textContent='Подходящих точек нет.';$('list').append(p);}$('more').hidden=list.length<=limit;
 }
 function fit(){if(snapshot)map.fitBounds(bounds(),{padding:[20,20]});}
 function drawArtwork(){
  if(!snapshot)return;const level=floor()==='all'?(snapshot.floors.includes('floor-1')?'floor-1':snapshot.floors[0]):floor();
  const nextUrl=URL.createObjectURL(new Blob([documentLevelSvg(svg,snapshot.map,level)],{type:'image/svg+xml'}));
  const next=L.imageOverlay(nextUrl,bounds(),{pane:'artwork'}).addTo(map);if(artwork)artwork.remove();if(url)URL.revokeObjectURL(url);url=nextUrl;artwork=next;
 }
 async function load(){
  const token=++epoch;controller?.abort();controller=new AbortController();busy=true;controls();$('retry').hidden=true;status('Загрузка точек…');
  try{const signal=AbortSignal.any([controller.signal,AbortSignal.timeout(25000)]);
   const [data,response]=await Promise.all([request(endpoint('read'),null,signal),fetch(endpoint('artwork'),{credentials:'same-origin',cache:'no-store',signal})]);if(!response.ok)throw Error('Не удалось загрузить схему карты.');const content=await response.text();if(token!==epoch)return;
   snapshot=data;svg=content;saved=documentPoints(data);points=structuredClone(saved);pending.clear();selected=null;conflict=null;placing=false;loadedScope={map:$('map').value,mode:$('mode').value};$('conflict').hidden=true;
   $('floor').replaceChildren(option('all','Все этажи'),...data.floors.map(id=>option(id,floorName(id))));$('point-floor').replaceChildren(...data.floors.map(id=>option(id,floorName(id))));
   const requested=new URLSearchParams(location.search).get('floor');if(data.floors.includes(requested))$('floor').value=requested;
   $('document').replaceChildren(option('','Выберите документ'),...data.documents.map(row=>option(row.id,row.name)));fit();drawArtwork();render();clearErrors();fields();status('Карта готова. Выберите этаж и точку.');
  }catch(error){if(token===epoch){status(error.message,true);$('retry').hidden=false;}}
  finally{if(token===epoch){busy=false;controls();render();}}
 }
 function showConflict(){
  if(!conflict||!dirty()){conflict=null;$('conflict').hidden=true;return;}
  const server=documentPoints(conflict);$('differences').textContent=[...pending].map(([id,change])=>{const remote=server.find(point=>point.id===id||(!change.pointId&&point.clientId===id));return change.title+'\nМои правки: '+JSON.stringify(change,null,2)+'\nСервер: '+(remote?JSON.stringify(documentChange(remote),null,2):'точка отсутствует');}).join('\n\n');$('conflict').hidden=false;
 }
 function showErrors(errors,changes){
  const first=Object.keys(errors||{}).find(key=>/^changes\.\d+\./.test(key));if(first){const index=Number(first.split('.')[1]);selected=points.find(point=>point.id===changes[index]?.clientId);fields();}
  for(const [key,messages] of Object.entries(errors||{})){const field=key.replace(/^changes\.\d+\./,'');fieldError(field,messages[0]);}
 }
 async function save(){
  if(busy||!dirty()||conflict)return;clearErrors();const changes=[...pending.values()].map(change=>{const value={...change},original=saved.find(point=>point.id===change.clientId);if(original){if(value.title===original.name)delete value.title;if(value.description===original.description)delete value.description;}return value;});
  const token=epoch,id=selected?.id;busy=true;controls();render();status('Сохранение…');
  try{let result;
   if(snapshot.capabilities?.batchPoints)result=await request(endpoint('batch'),{version:snapshot.version,changes});
   else{
    const change=changes[0];if(changes.length!==1||change.archived||change.description||(change.pointId&&change.title))throw Error('Сервер пока не поддерживает пакетные правки. Все черновики сохранены в памяти; повторите после обновления сервера.');
    const payload={version:snapshot.version,...Object.fromEntries(Object.entries(change).filter(([key])=>['kind','pointId','positionId','floor','x','y','title','documentId'].includes(key)))};
    const response=await request(endpoint('save'),payload);result={...response,applied:[{clientId:change.clientId,pointId:response.pointId,positionId:change.positionId}]};
   }
   if(token!==epoch)return;snapshot=result.snapshot;saved=documentPoints(snapshot);points=structuredClone(saved);pending.clear();const applied=result.applied?.find(row=>row.clientId===id);
   selected=points.find(point=>point.id===id)||points.find(point=>point.pointId===applied?.pointId&&(!applied.positionId||point.positionId===applied.positionId));placing=false;render();fields();status('Изменения сохранены. Публичная карта обновлена.');
  }catch(error){if(token!==epoch)return;status(error.status===401||error.status===419?'Сессия истекла. Все правки остаются здесь; войдите в админку в другой вкладке.':error.message,true);if(error.status===422)showErrors(error.data?.errors,changes);
   if(error.status===409)try{const remote=await request(endpoint('read'));if(token===epoch){conflict=remote;showConflict();}}catch{if(token===epoch)status('Не удалось получить новую версию. Все правки остаются здесь.',true);}
  }finally{if(token===epoch){busy=false;controls();render();}}
 }
 for(const id of ['map','mode'])$(id).onchange=()=>{if(busy)return;if(confirmLoss())load();else $(id).value=loadedScope[id];};
 function refreshArtwork(){try{drawArtwork();$('retry').hidden=true;render();}catch(error){status(error.message,true);$('retry').hidden=false;}}
 $('floor').onchange=()=>{if(busy)return;placing=false;refreshArtwork();if(selected&&!visible().includes(selected))selected=null;clearErrors();fields();render();};
 for(const id of ['search','category','archived'])$(id).addEventListener('input',()=>{limit=100;if(selected&&!visible().includes(selected))selected=null;clearErrors();fields();render();});
 $('fit').onclick=fit;$('save').onclick=save;$('retry').onclick=()=>{if(!dirty())load();else refreshArtwork();};$('more').onclick=()=>{limit+=100;render();};
 $('add').onclick=()=>{if(busy)return;if(floor()==='all'){status('Сначала выберите этаж для установки точки.',true);return;}if(pending.size>=100){status('Сначала сохраните 100 правок.',true);return;}placing='new';$('add').setAttribute('aria-pressed','true');status('Нажмите нужное место на карте. Escape — отмена.');};
 $('place').onclick=()=>{if(selected&&!busy){placing='move';status('Нажмите новое место точки на карте.');}};
 map.on('click',event=>{if(!placing||busy)return;
  if(placing==='new'){if(floor()==='all'||pending.size>=100)return;const position={x:event.latlng.lng/size().width*100,y:-event.latlng.lat/size().height*100};if(!validCoordinates(position)){status('Выберите место в границах схемы.',true);return;}
   selected={id:'editor-'+crypto.randomUUID().replaceAll('-',''),kind:$('category').value==='exit'?'exit':'document',pointId:null,positionId:null,name:'Новая точка',description:'',floor:floor(),archived:false,...position};points.push(selected);changed({});
  }else move(selected,event.latlng);placing=false;$('add').setAttribute('aria-pressed','false');clearErrors();fields();render();
 });
 $('discard-point').onclick=()=>{if(busy||!selected)return;selected=discardDocumentDraft(points,saved,pending,selected);placing=false;$('add').setAttribute('aria-pressed','false');clearErrors();showConflict();render();fields();status('Правки выбранной точки отменены.'+(dirty()?' Осталось несохранённых точек: '+pending.size:''));};
 $('discard').onclick=()=>{if(!busy&&window.confirm('Отменить все несохранённые правки?'))load();};
 $('archive').onclick=()=>{if(selected&&!busy){changed({archived:!selected.archived});fields();}};
 for(const id of ['name','description'])$(id).oninput=()=>{if(!selected)return;changed({[id]:$(id).value});};
 for(const axis of ['x','y'])$(axis).oninput=()=>{if(!selected)return;const number=$(axis).value===''?null:Number($(axis).value);changed({[axis]:number});if(number===null||!Number.isFinite(number)||number<0||number>100)fieldError(axis,'Введите координату от 0 до 100.');else{$(axis).classList.remove('is-invalid');$(axis).removeAttribute('aria-invalid');$(axis+'-error').textContent='';}controls();};
 $('point-floor').onchange=()=>{if(selected){changed({floor:$('point-floor').value});fields();}};
 $('type').onchange=()=>{if(selected&&!selected.pointId){changed({kind:$('type').value,documentId:null});fields();}};
 $('document').onchange=()=>{if(selected&&!selected.pointId)changed({documentId:$('document').value?Number($('document').value):null});};
 $('server').onclick=()=>{if(window.confirm('Отменить свои правки и загрузить серверную версию?'))load();};
 $('rebase').onclick=()=>{if(!conflict)return;try{if(!conflict.capabilities?.batchPoints&&[...pending.values()].some(change=>!change.pointId))throw Error('Для безопасного повтора новой точки дождитесь обновления сервера. Черновики остаются здесь.');const result=rebaseDocumentDrafts(conflict,points,pending),id=result.ids.get(selected?.id)||selected?.id;snapshot=conflict;saved=documentPoints(snapshot);points=result.points;pending=result.pending;selected=points.find(point=>point.id===id);conflict=null;clearErrors();showConflict();render();fields();status('Правки объединены с новой версией. Проверьте карту и нажмите «Сохранить».');}catch(error){status(error.message,true);}};
 root.addEventListener('keydown',event=>{if(event.key==='Escape'){placing=false;$('add').setAttribute('aria-pressed','false');status('Установка точки отменена.');}});
 window.addEventListener('beforeunload',event=>{if(dirty()){event.preventDefault();event.returnValue='';}});document.addEventListener('turbo:before-visit',event=>{if(!confirmLoss())event.preventDefault();});
 new ResizeObserver(()=>map.invalidateSize({pan:false})).observe($('canvas'));
 const query=new URLSearchParams(location.search);if([...$('map').options].some(row=>row.value===query.get('map')))$('map').value=query.get('map');if(['pve','regular'].includes(query.get('mode')))$('mode').value=query.get('mode');load();
}
