// SQL хранит исходные проценты атласа; представление переводит их в единую плоскость уровня.
export function documentViewport(map) {
  const panel=map.documentPanels?.['floor-1']||Object.values(map.documentPanels||{})[0];
  return panel?{width:map.width*panel.width/100,height:map.height*panel.height/100}:{width:map.width,height:map.height};
}

export function documentPointPosition(point,map,alternate=false) {
  const x=alternate?point.x2:point.x,y=alternate?point.y2:point.y;
  const panel=map.documentPanels?.[point.floor]||Object.values(map.documentPanels||{}).find(p=>x>=p.x&&x<=p.x+p.width&&y>=p.y&&y<=p.y+p.height);
  return panel?{x:(x-panel.x)/panel.width*100,y:(y-panel.y)/panel.height*100}:{x,y};
}

export function documentLayerStyle(ids,level) {
  const selected=/^floor--?\d+$/.test(level)?level:'floor-1';
  const current=Number(selected.replace('floor-',''));
  return ids.filter(id=>/^floor--?\d+$/.test(id)).map(id=>{
    const n=Number(id.replace('floor-','')),visible=n<=current;
    const dim=n<current&&!(n===0&&current===1);
    return `#${id}{display:${visible?'inline':'none'}!important;filter:${dim?'brightness(.35)':'none'}!important}`;
  }).join('\n');
}

/** Создаёт одну SVG-схему, включая совместимость с прежним пакетом до переключения API. */
export function documentLevelSvg(text,map,level,Parser=globalThis.DOMParser,Serializer=globalThis.XMLSerializer) {
  const root=new Parser().parseFromString(text,'image/svg+xml').documentElement;
  if(root.localName!=='svg'||root.querySelector('parsererror'))throw Error('Некорректная схема документов');
  let svg=root;const selected=/^floor--?\d+$/.test(level)?level:'floor-1';
  if(!map.layeredFile&&map.documentPanels){
    const floors=Object.keys(map.documentPanels),index=Math.max(0,floors.indexOf(selected));
    svg=[...root.children].filter(n=>n.localName==='svg')[index];
    if(!svg)throw Error('Уровень отсутствует в прежней схеме');
    svg.removeAttribute('x');svg.removeAttribute('y');
  }else{
    const style=root.ownerDocument.createElementNS('http://www.w3.org/2000/svg','style');
    style.textContent=documentLayerStyle([...root.querySelectorAll('[id]')].map(n=>n.id),level);root.append(style);
  }
  return new Serializer().serializeToString(svg);
}
