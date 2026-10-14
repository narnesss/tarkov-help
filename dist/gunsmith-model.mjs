export const BUILD_VIEWS={shopping:'Список покупок',inspect:'Осмотр оружия',modding:'Окно модификации'};
export function assemblyFrames(record){return (record?.builds||[]).flatMap(build=>[...(build.views?.inspect||[]),...(build.views?.modding||[])]);}
export function shoppingRows(build,items={}){
 return (build.shopping?.items||[]).map((row,index)=>{
  const item=Array.isArray(items)?items.find(i=>i.id===row.itemId):items[row.itemId];
  return {...row,key:build.objectiveId+':'+(row.alternativeGroup||'part')+':'+(row.itemId||index),item,name:item?.name||row.name||'Название детали не подтверждено',count:Number.isFinite(row.count)&&row.count>0?row.count:1};
 });
}
export function shoppingText(build,items){
 return [build.weaponName,...shoppingRows(build,items).map(row=>`${row.count} × ${row.name}${row.alternativeGroup?' — один из вариантов':''}`)].join('\n');
}
