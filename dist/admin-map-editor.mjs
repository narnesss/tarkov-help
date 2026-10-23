import {CATEGORIES,LOOT_NAMES,titleOf,iconPath,poiFloor as sourceFloor,validPosition} from './raid-model.mjs';
import {mapCrs,pointCoordinates,worldCoordinates,sourceCalibration,floorHeight,rasterTileLayer} from './raid-map-geometry.mjs';
import {discardPointDraft} from './admin-map-drafts.mjs';

const root=document.getElementById('map-editor'),$=id=>document.getElementById('me-'+id),L=window.L;
const element=(tag,text='')=>{const n=document.createElement(tag);n.textContent=text;return n;};
const asset=path=>{const url=new URL(path,root.dataset.assets);if(!['http:','https:'].includes(url.protocol))throw Error('Недопустимый адрес схемы.');return url.href.replace(/%7B([zxy])%7D/gi,'{$1}');};
const poiFloor=(definition,point)=>point.editorPosition&&point.floorOverride?point.floorOverride:sourceFloor(definition,point);
const option=(value,text)=>{const n=element('option',text);n.value=value;return n;};
let snapshot,points=[],map,markers,artwork,selected,pending=new Map(),epoch=0,placing=false,busy=false,limit=100,loadedScope,conflict;
const floors=()=>[...new Map([snapshot.definition.baseFloor,...snapshot.definition.floors].map(f=>[f.id,f])).values()];
const floor=()=>$('floor').value;
const status=(message,error=false)=>{$('status').textContent=message;$('status').setAttribute('role',error?'alert':'status');};
const scope=()=>new URLSearchParams({map:$('map').value,mode:$('mode').value}).toString();
const dirty=()=>pending.size>0;
const confirmLoss=()=>!dirty()||window.confirm('Есть несохранённые изменения. Отменить их и сменить карту?');
async function request(url,body){
  const response=await fetch(url,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(20000),
    headers:{Accept:'application/json',...(body?{'Content-Type':'application/json','X-CSRF-TOKEN':root.dataset.csrf}:{})},...(body?{body:JSON.stringify(body)}:{})});
  const data=await response.json().catch(()=>({message:'Не удалось обработать ответ сервера.'}));
  if(!response.ok)throw Object.assign(Error(data.message||'Не удалось сохранить карту.'),{status:response.status,data});return data;
}
function controls(){
  $('save').disabled=busy||!dirty()||!!conflict||!!root.querySelector('.me-coordinates .is-invalid');$('discard').disabled=busy||!dirty();
  for(const id of ['map','mode','floor','add','fit','rebase','server','retry'])$(id).disabled=busy||!snapshot;
  for(const input of $('fields').querySelectorAll('input,textarea,select,button'))input.disabled=busy;
  $('discard-point').disabled=busy||!selected||(!pending.has(selected.id)&&!$('fields').querySelector('.is-invalid'));
  $('type').disabled=busy||!selected?.isNew;$('loot').disabled=busy||!selected?.isNew;
  $('save').textContent=`Сохранить${dirty()?' ('+pending.size+')':''}`;
}
function draft(point){return {id:point.id,new:!!point.isNew,name:point.name||titleOf(point),description:point.description||'',category:point.category,
  lootType:point.lootType||null,itemId:point.isNew?(point.itemIds?.[0]||null):null,position:{...point.position},floor:poiFloor(snapshot.definition,point),archived:!!point.cmsArchived};}
function changed(){
  if(!selected)return;if(!pending.has(selected.id)&&pending.size>=100){status('Сохраните текущие 100 изменений перед следующими.',true);return false;}
  pending.set(selected.id,draft(selected));controls();status(`Несохранённых точек: ${pending.size}`);return true;
}
function canEdit(point=selected){if(busy||!point)return false;if(!pending.has(point.id)&&pending.size>=100){status('Сохраните текущие 100 изменений перед следующими.',true);fields();return false;}return true;}
function markerIcon(point){const n=element('span'),img=element('img');img.src=asset(iconPath(point));img.alt='';n.append(img);
  return L.divIcon({html:n,className:'me-marker'+(point.id===selected?.id?' is-selected':'')+(pending.has(point.id)?' is-dirty':'')+(point.cmsArchived?' is-archived':''),iconSize:[44,44],iconAnchor:[22,22]});}
function visible(){const query=$('search').value.toLocaleLowerCase('ru');return points.filter(p=>validPosition(p.position)&&CATEGORIES[p.category]&&
  (floor()==='all'||poiFloor(snapshot.definition,p)===floor())&&($('category').value==='all'||p.category===$('category').value)&&
  ($('archived').checked||!p.cmsArchived)&&(!query||[titleOf(p),p.name,p.description,p.id].join(' ').toLocaleLowerCase('ru').includes(query)));}
function render(){
  if(!map)return;markers.clearLayers();const list=visible();$('count').textContent=`Точек в выбранном слое: ${list.length}`;$('list').replaceChildren();
  for(const point of list){const marker=L.marker(pointCoordinates(snapshot.definition,floor(),point),{icon:markerIcon(point),draggable:!busy,keyboard:true,autoPanOnFocus:false,title:titleOf(point),poiId:point.id}).addTo(markers);
    marker.on('click',()=>select(point));marker.on('dragstart',()=>{selected=point;fields();});marker.on('dragend',()=>{move(point,marker.getLatLng());render();});
    marker.getElement().setAttribute('aria-label',titleOf(point));marker.getElement().addEventListener('keydown',event=>{
      if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key)||!canEdit(point))return;event.preventDefault();event.stopPropagation();selected=point;
      const step=event.shiftKey?10:1,position={...point.position};if(event.key==='ArrowLeft')position.x-=step;if(event.key==='ArrowRight')position.x+=step;if(event.key==='ArrowUp')position.z+=step;if(event.key==='ArrowDown')position.z-=step;
      point.floorOverride=poiFloor(snapshot.definition,point);point.position=position;point.editorPosition=true;changed();render();fields();markers.getLayers().find(m=>m.options.poiId===point.id)?.getElement().focus({preventScroll:true});
    });
  }
  for(const p of list.slice(0,limit)){const b=element('button'),img=element('img');b.type='button';img.src=asset(iconPath(p));img.alt='';b.append(img,element('span',titleOf(p)));if(p.id===selected?.id)b.classList.add('is-selected');b.onclick=()=>{select(p);map.panTo(pointCoordinates(snapshot.definition,floor(),p));};$('list').append(b);}
  $('more').hidden=list.length<=limit;
}
function select(point){const previous=selected?.id;clearErrors();selected=point;fields();for(const marker of markers.getLayers()){if(![previous,point.id].includes(marker.options.poiId))continue;const p=points.find(p=>p.id===marker.options.poiId);if(p)marker.setIcon(markerIcon(p));}}
function fields(){
  $('fields').hidden=!selected;$('help').hidden=!!selected;if(!selected){controls();return;}
  $('name').value=selected.name||titleOf(selected);$('description').value=selected.description||'';$('type').value=selected.category;$('type').disabled=!selected.isNew;
  $('loot-label').hidden=selected.category!=='loot';$('loot').value=selected.lootType||'wooden-crate';$('loot').disabled=!selected.isNew;
  $('point-floor').value=poiFloor(snapshot.definition,selected);for(const axis of ['x','y','z'])$(axis).value=Number(selected.position[axis].toFixed(5));
  $('item-fields').hidden=!selected.isNew||selected.category!=='loose-loot';
  $('archive').textContent=selected.cmsArchived?'Восстановить':'В архив';controls();
}
function move(point,ll){
  if(!canEdit(point))return false;selected=point;
  point.position=worldCoordinates(snapshot.definition,floor(),ll,point.position.y);point.editorPosition=true;
  point.floorOverride=floor()==='all'?poiFloor(snapshot.definition,point):floor();changed();fields();return true;
}
function fit(){const d=snapshot.definition,s=sourceCalibration(d,floor());map.fitBounds(s?[[-s.height,0],[0,s.width]]:d.bounds.map(([x,z])=>[z,x]),{padding:[20,20]});}
async function svgArtwork(path,layer){
  const d=snapshot.definition,response=await fetch(asset(path),{credentials:'omit',signal:AbortSignal.timeout(20000)});if(!response.ok)throw Error('Не удалось загрузить схему карты.');
  const svg=new DOMParser().parseFromString(await response.text(),'image/svg+xml').documentElement;
  if(svg.localName!=='svg'||svg.querySelector('parsererror'))throw Error('Некорректная схема карты.');
  svg.querySelectorAll('script,foreignObject').forEach(n=>n.remove());
  for(const n of [svg,...svg.querySelectorAll('*')])for(const attr of [...n.attributes])if(attr.name.startsWith('on')||/href$/.test(attr.name)&&!attr.value.startsWith('#'))n.removeAttribute(attr.name);
  svg.setAttribute('preserveAspectRatio','none');for(const child of [...svg.children])if(child.localName==='g'&&child.id){const base=child.id===d.baseAsset.baseLayer||child.getAttribute('data-keep-with-group')===d.baseAsset.baseLayer;child.style.display=base||child.id===layer?'':'none';child.style.opacity=base&&layer&&layer!==d.baseAsset.baseLayer?'.42':'1';}
  return L.svgOverlay(svg,(d.svgBounds||d.bounds).map(([x,z])=>[z,x]),{pane:'artwork'});
}
async function drawArtwork(token){
  const d=snapshot.definition,f=d.floors.find(f=>f.id===floor()),s=sourceCalibration(d,floor());
  const a=s?{type:'image',path:s.path}:f?.asset||d.baseAsset;let next;
  const bounds=(a.bounds||d.svgBounds||d.bounds).map(([x,z])=>[z,x]);
  if(a.type==='svg'){
    next=await svgArtwork(a.path,f?.svgLayer);
  }else if(a.type==='tiles'){
    next=rasterTileLayer(L,asset(a.path),{tileSize:a.tileSize||256,minNativeZoom:a.minNativeZoom??d.minZoom,maxNativeZoom:a.maxNativeZoom??d.maxZoom,maxZoom:8,noWrap:true,bounds:d.bounds.map(([x,z])=>[z,x]),pane:'artwork'}).on('tileerror',()=>{if(token!==epoch)return;status('Часть схемы не загрузилась. Повторите загрузку этажа.',true);$('retry').hidden=false;});
    if(d.baseAsset.type==='svg')next=L.layerGroup([await svgArtwork(d.baseAsset.path),next]);
  }
  else next=L.imageOverlay(asset(a.path),s?[[-s.height,0],[0,s.width]]:bounds,{pane:'artwork'});
  if(token!==epoch)return;if(artwork)artwork.remove();artwork=next.addTo(map);
}
async function load(){
  const token=++epoch;busy=true;controls();status('Загрузка точек…');$('retry').hidden=true;
  try{const data=await request(root.dataset.endpoint+'?'+scope());if(token!==epoch)return;snapshot=data;loadedScope={map:$('map').value,mode:$('mode').value};
    points=structuredClone(data.data.pois);pending.clear();selected=null;conflict=null;placing=false;$('conflict').hidden=true;
    $('floor').replaceChildren(option('all','Все этажи'),...floors().map(f=>option(f.id,f.name)));$('point-floor').replaceChildren(...floors().map(f=>option(f.id,f.name)));
    if(map)map.remove();artwork=null;map=L.map($('canvas'),{crs:mapCrs(L,data.definition),minZoom:-5,maxZoom:8,zoomControl:true});map.createPane('artwork').style.zIndex=200;markers=L.layerGroup().addTo(map);
    map.on('click',event=>{
      if(!placing||busy)return;
      if(placing==='new'){
        if(floor()==='all'){status('Выберите конкретный этаж для новой точки.',true);return;}
        if(pending.size>=100){status('Сначала сохраните 100 правок.',true);return;}
        const position=worldCoordinates(snapshot.definition,floor(),event.latlng,0);position.y=floorHeight(snapshot.definition,floor(),position);
        selected={id:'editor-'+crypto.randomUUID().replaceAll('-',''),isNew:true,name:'Новая точка',description:'',category:$('category').value==='all'?'quest':$('category').value,position,editorPosition:true,floorOverride:floor()};
        if(selected.category==='loot')selected.lootType='wooden-crate';points.push(selected);changed();
      }else move(selected,event.latlng);
      placing=false;$('add').setAttribute('aria-pressed','false');render();fields();
    });
    fit();await drawArtwork(token);if(token!==epoch)return;render();fields();status('Карта готова. Выберите этаж и точку.');
  }catch(error){if(token===epoch){status(error.status===401||error.status===419?'Сессия истекла. Войдите в админку повторно.':error.message,true);$('retry').hidden=false;}}
  finally{if(token===epoch){busy=false;controls();render();}}
}
function clearErrors(){for(const n of root.querySelectorAll('.me-error'))n.textContent='';for(const n of root.querySelectorAll('.is-invalid')){n.classList.remove('is-invalid');n.removeAttribute('aria-invalid');}}
function showConflict(){
  if(!conflict||!dirty()){conflict=null;$('conflict').hidden=true;return;}
  const fields=p=>({name:p.name,description:p.description,position:p.position,floor:p.floorOverride||p.floor,archived:!!(p.cmsArchived||p.archived)});
  $('differences').textContent=[...pending.values()].map(c=>{const server=conflict.data.pois.find(p=>p.id===c.id);return `${c.name}\nМои правки: ${JSON.stringify(fields(c),null,2)}\nСервер: ${server?JSON.stringify(fields(server),null,2):'точка отсутствует'}`;}).join('\n\n');$('conflict').hidden=false;
}
function showErrors(errors,changes){
  const entries=Object.entries(errors||{}),first=entries[0]?.[0].match(/^changes\.(\d+)\./);if(first){selected=points.find(p=>p.id===changes[Number(first[1])].id);fields();}
  for(const [key,messages] of entries){const field=key.split('.').slice(2).join('.'),id=field.startsWith('position')?'position':({category:'type',floor:'point-floor',lootType:'loot'}[field]||field);
    if($(id+'-error'))$(id+'-error').textContent=messages.join(' ');for(const target of id==='position'?['x','y','z']:[id])if($(target)){$(target).classList.add('is-invalid');$(target).setAttribute('aria-invalid','true');}}
}
async function save(){
  clearErrors();const changes=[...pending.values()];if(!changes.length||busy)return;busy=true;controls();render();status('Сохранение…');
  try{const result=await request(root.dataset.save+'?'+scope(),{revision:snapshot.revision,definitionRevision:snapshot.definitionRevision,changes});snapshot.revision=result.revision;
    for(const point of points){delete point.isNew;}snapshot.data.pois=structuredClone(points);pending.clear();status('Изменения сохранены. Публичная карта обновлена.');
    try{const fresh=await request(root.dataset.endpoint+'?'+scope());const id=selected?.id;snapshot=fresh;points=structuredClone(fresh.data.pois);selected=points.find(p=>p.id===id);fields();}catch{status('Изменения сохранены. Не удалось обновить просмотр; повторная загрузка покажет актуальную карту.');}
  }catch(error){
    status(error.status===419?'Сессия истекла. Правки остаются здесь; войдите в админку в другой вкладке и повторите сохранение.':error.message,true);
    if(error.status===422)showErrors(error.data.errors,changes);
    if(error.status===409){try{conflict=await request(root.dataset.endpoint+'?'+scope());showConflict();}catch{status('Не удалось получить новую версию. Ваши правки сохранены; повторите сохранение.',true);}}
  }finally{busy=false;controls();render();}
}
for(const [key,[name]] of Object.entries(CATEGORIES)){ $('category').append(option(key,name));$('type').append(option(key,name)); }
for(const [key,name] of Object.entries(LOOT_NAMES))$('loot').append(option(key,name));
for(const id of ['map','mode'])$(id).onchange=()=>{if(busy)return;if(confirmLoss())load();else $(id).value=loadedScope[id];};
$('floor').onchange=async()=>{const token=++epoch;placing=false;busy=true;controls();render();status('Загрузка этажа…');try{await drawArtwork(token);if(token===epoch){if(selected&&!visible().includes(selected))selected=null;fields();status(floor()==='all'?'Все этажи показаны на основном плане. Для установки точки выберите этаж.':'Выбран этаж: '+$('floor').selectedOptions[0].textContent);}}catch(error){if(token===epoch){status(error.message,true);$('retry').hidden=false;}}finally{if(token===epoch){busy=false;controls();render();}}};
for(const id of ['search','category','archived'])$(id).addEventListener('input',()=>{limit=100;if(selected&&!visible().includes(selected))selected=null;fields();render();});
$('more').onclick=()=>{limit+=100;render();};$('fit').onclick=fit;$('save').onclick=save;$('retry').onclick=()=>{if(!snapshot)start();else if(dirty())drawArtwork(++epoch).then(render).catch(e=>status(e.message,true));else load();};
$('add').onclick=()=>{if(floor()==='all'){status('Сначала выберите этаж для установки точки.',true);return;}placing='new';$('add').setAttribute('aria-pressed','true');status('Нажмите нужное место на карте. Escape — отмена.');};
$('place').onclick=()=>{placing='move';status('Нажмите новое место точки на карте.');};
$('archive').onclick=()=>{if(!canEdit())return;selected.cmsArchived=!selected.cmsArchived;changed();render();fields();};
$('discard').onclick=()=>{if(window.confirm('Отменить все несохранённые правки?'))load();};
$('discard-point').onclick=()=>{if(busy||!selected)return;selected=discardPointDraft(points,snapshot.data.pois,pending,selected.id);placing=false;$('add').setAttribute('aria-pressed','false');clearErrors();showConflict();render();fields();controls();status('Правки выбранной точки отменены.'+(dirty()?` Осталось несохранённых точек: ${pending.size}`:''));};
for(const id of ['name','description'])$(id).oninput=()=>{if(!canEdit())return;$(id).classList.remove('is-invalid');$(id).removeAttribute('aria-invalid');$(id+'-error').textContent='';selected[id]=$(id).value;changed();};
for(const axis of ['x','y','z'])$(axis).oninput=()=>{if(!canEdit())return;const n=Number($(axis).value);if($(axis).value===''||!Number.isFinite(n)||Math.abs(n)>10000){$(axis).classList.add('is-invalid');$(axis).setAttribute('aria-invalid','true');$('position-error').textContent='Введите число от −10000 до 10000.';controls();return;}$(axis).classList.remove('is-invalid');$(axis).removeAttribute('aria-invalid');if(!['x','y','z'].some(a=>$(a).classList.contains('is-invalid')))$('position-error').textContent='';selected.position[axis]=n;selected.editorPosition=true;selected.floorOverride=$('point-floor').value;changed();render();};
$('point-floor').onchange=()=>{if(!canEdit())return;selected.floorOverride=$('point-floor').value;selected.editorPosition=true;selected.position.y=floorHeight(snapshot.definition,selected.floorOverride,selected.position);changed();render();fields();};
$('type').onchange=()=>{if(!selected?.isNew||busy)return;selected.category=$('type').value;if(selected.category==='loot')selected.lootType=$('loot').value;else delete selected.lootType;changed();render();fields();};
$('loot').onchange=()=>{if(!selected?.isNew||busy)return;selected.lootType=$('loot').value;changed();render();};
$('item-find').onclick=async()=>{const id=selected?.id,owner=epoch;try{const data=await request(root.dataset.items+'?'+new URLSearchParams({search:$('item-search').value}));if(selected?.id!==id||owner!==epoch)return;$('itemId').replaceChildren(option('','Выберите предмет'),...data.items.map(i=>option(i.id,i.name)));}catch(e){if(owner===epoch)status(e.message,true);}};
$('itemId').onchange=()=>{if(!selected?.isNew)return;selected.itemIds=$('itemId').value?[$('itemId').value]:[];changed();};
$('server').onclick=()=>{if(window.confirm('Отменить свои правки и загрузить серверную версию?'))load();};
$('rebase').onclick=()=>{if(!conflict)return;if(conflict.definitionRevision!==snapshot.definitionRevision){status('Изменена схема этажей. Скопируйте свои координаты из сравнения, примите серверную версию и повторите правки на новой схеме.',true);return;}const serverPoints=structuredClone(conflict.data.pois);for(const c of pending.values()){const index=serverPoints.findIndex(p=>p.id===c.id),local=points.find(p=>p.id===c.id);if(index<0&&!c.new){status('Одна из точек удалена на сервере. Примите серверную версию и создайте её заново.',true);return;}if(index>=0&&c.new){c.new=false;c.itemId=null;delete local.isNew;}const merged={...(index<0?local:serverPoints[index]),name:c.name,description:c.description,position:c.position,floorOverride:c.floor,editorPosition:true,cmsArchived:c.archived};if(index<0)serverPoints.push(merged);else serverPoints[index]=merged;}
  snapshot=conflict;points=serverPoints;conflict=null;$('conflict').hidden=true;selected=points.find(p=>p.id===selected?.id);controls();render();fields();status('Правки объединены с новой версией. Проверьте карту и нажмите «Сохранить».');};
window.addEventListener('beforeunload',event=>{if(dirty()){event.preventDefault();event.returnValue='';}});
root.addEventListener('keydown',event=>{if(event.key==='Escape'){placing=false;status('Установка точки отменена.');}});
new ResizeObserver(()=>map?.invalidateSize({pan:false})).observe($('canvas'));
function start(){request(root.dataset.endpoint).then(data=>{$('map').replaceChildren(...data.maps.map(m=>option(m.id,m.name||m.displayName)));return load();}).catch(e=>{status(e.message,true);$('retry').hidden=false;$('retry').disabled=false;});}start();
