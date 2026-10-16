/** Проверяет принадлежность набора и явно указанных локаций до построения карты. */
export function scopedRaidPoints(payload,mapId,mode){
  if(payload?.mapId!==mapId||!Array.isArray(payload.pois))throw new Error('Набор точек относится к другой карте. Обнови страницу');
  return payload.pois.filter(point=>{
    if(point.cmsArchived||point.sourceStatus==='unverified-coordinates')return false;
    for(const field of ['mapId','sourceMapId'])if(typeof point[field]==='string'&&point[field]!==mapId)return false;
    return !point.gameMode||point.gameMode==='all'||point.gameMode===mode;
  });
}
