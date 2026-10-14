/** A verified source catalog overlays cache/API data without dropping stored IDs. */
export function applyCatalogUpdate(bundle,catalog) {
 const snapshot=catalog?.modes?.[bundle.meta?.mode]?.tasks;
 if(!snapshot)return bundle;
 const incoming=new Map(bundle.tasks.map(t=>[t.id,t])),tasks=snapshot.map(verified=>{
  const fresh=incoming.get(verified.id);incoming.delete(verified.id);
  if(!fresh)return verified;
  const merged={...verified,...fresh};
  // A fresh API may omit these or still carry the incomplete Kappa field.
  for(const [key,value] of Object.entries(verified))if(key.startsWith('catalog')||key==='kappaRequired')merged[key]=value;
  return merged;
 });
 for(const fresh of incoming.values())tasks.push({...fresh,kappaRequired:false,catalogSection:'supplementary',catalogKind:'additional'});
 return {...bundle,tasks,catalogSummary:{...catalog.totals,sourceUrl:catalog.sourceUrl,fetchedAt:catalog.fetchedAt,supplementary:tasks.filter(t=>t.catalogSection==='supplementary').length}};
}
