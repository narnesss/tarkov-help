import {pageDataset} from './page-data.mjs?v=documents-access-20261020';
import {pageParams,initializeSiteRoutes} from './site-routes.mjs?v=page-content-20261017';
import {external} from './guide-common.mjs?v=documents-access-20261020';import {sourcePoint} from './quest-map-model.mjs?v=page-content-20261017';
import {QuestMapFocusReceiver} from './quest-map-messages.mjs?v=page-content-20261017';
const $=id=>document.getElementById(id),textNode=text=>{const n=document.createElement('span');n.textContent=text;return n;};
function showError(message){const root=$('qmap-loading');root.hidden=false;root.replaceChildren(textNode(message));const retry=document.createElement('button');retry.textContent='Повторить';retry.type='button';retry.onclick=()=>location.reload();root.append(retry);}
async function start(){
 const params=pageParams();document.body.classList.toggle('qmap-embed',params.get('embed')==='quest'||globalThis.parent!==globalThis);
 const index=await pageDataset('source-map','data/guide/quest-map-index.json',{view:'detail',id:params.get('quest'),map:params.get('map'),mode:params.get('mode')||'pve'}),asset=index.sourceMaps?.[params.get('map')],points=index.sourceQuests?.[params.get('quest')]?.[params.get('map')];
 if(!asset||!points?.length||!asset.width||!asset.height)throw Error('Для этого задания нет меток на выбранной схеме.');
 const map=L.map('qmap',{crs:L.CRS.Simple,minZoom:-6,maxZoom:2,zoomSnap:.25,zoomDelta:.5,scrollWheelZoom:true,attributionControl:false});
 const bounds=L.latLngBounds([-asset.height,0],[0,asset.width]);const image=L.imageOverlay(asset.src,bounds,{interactive:false,attribution:'Авторы исходной карты'}).addTo(map);
 image.on('error',()=>{showError('Не удалось загрузить схему.');});
 image.on('load',()=>{$('qmap-loading').hidden=true;});
 if(asset.sourceUrl){const credit=external('Источник схемы ↗',asset.sourceUrl);$('qmap-title').after(credit);}
 $('qmap-title').textContent=asset.ru;$('qmap-count').textContent=points.length+' точек задания';
 if(typeof asset.sourceUrl==='string'&&/^https:\/\//.test(asset.sourceUrl))$('qmap-source').href=asset.sourceUrl;
 const positions=[],markers=[];
 for(const [i,point] of points.entries()){
  const position=sourcePoint(point,asset);positions.push(position);
  const icon=L.divIcon({className:'qmap-pin',html:textNode(String(i+1)),iconSize:[30,30],iconAnchor:[15,15]});
  const marker=L.marker(position,{icon,keyboard:true,title:(i+1)+'. '+point.name}).addTo(map);markers.push(marker);marker.bindTooltip(textNode(point.name),{direction:'top',offset:[0,-16]});const popup=document.createElement('div');popup.className='qmap-popup';popup.textContent=point.name;marker.bindPopup(popup,{autoPan:true,autoPanPadding:[16,16],keepInView:true});
 }
 const fit=()=>map.fitBounds(bounds,{padding:[12,12],animate:false}),focus=()=>map.fitBounds(L.latLngBounds(positions),{padding:[65,65],maxZoom:0,animate:false});
 const receiver=new QuestMapFocusReceiver(()=>({questId:params.get('quest'),mode:params.get('mode'),mapId:params.get('map'),viewId:params.get('view')||''}),message=>{
  const indexes=[...new Set((message.pointIndexes||[]).filter(i=>i<positions.length))];map.closePopup();
  if(!indexes.length){focus();return;}
  if(indexes.length===1){map.setView(positions[indexes[0]],Math.max(map.getZoom(),0),{animate:false});markers[indexes[0]].openPopup();}
  else map.fitBounds(L.latLngBounds(indexes.map(i=>positions[i])),{padding:[65,65],maxZoom:0,animate:false});
 });
 $('qmap-fit').onclick=fit;$('qmap-points').onclick=focus;$('qmap-full').onclick=async()=>{try{if(document.fullscreenElement)await document.exitFullscreen();else await document.documentElement.requestFullscreen();}catch{}};
 if(globalThis.ResizeObserver)new ResizeObserver(()=>map.invalidateSize({animate:false})).observe($('qmap'));
 fit();$('qmap-loading').hidden=true;receiver.ready();window.addEventListener('pagehide',()=>receiver.destroy(),{once:true});
}
initializeSiteRoutes().then(start).catch(error=>showError(error.message));
