// Наборы определяются выбранной страницей, а не общим шаблоном справочника.
export async function loadReferenceSources(mode,{dataset,json},tab='items',itemId=null){
 const emptyQuests={cms:true,tasks:[],questItems:[],maps:[]};
 if(itemId){
  const catalog=await dataset('items-'+mode,`data/guide/items-${mode}.json`,{view:'detail',id:itemId});
  return [catalog.related?.reference||{},catalog,catalog.related?.quests||emptyQuests,{maps:{}},{profiles:[]},null];
 }
 const needsItems=['items','ammo','needed','barters','crafts','hideout','traders'].includes(tab);
 const needsQuests=['needed','crafts','barters'].includes(tab);
 const [reference,catalog,quests,btr,bosses]=await Promise.all([
  dataset('reference-'+mode,`data/guide/reference-${mode}.json`,{view:'catalog',section:tab}),
  needsItems?dataset('items-'+mode,`data/guide/items-${mode}.json`):{items:[]},
  needsQuests?dataset('quests-'+mode,`data/guide/quests-${mode}.json`,{view:'catalog'}):['items','ammo','bosses','btr'].includes(tab)?dataset('quests-'+mode,`data/guide/quests-${mode}.json`,{view:'lookup'}):emptyQuests,
  tab==='btr'?json('data/guide/btr.json'):{maps:{}},tab==='bosses'?json('data/guide/bosses.json'):{profiles:[]},
 ]);
 return [reference,catalog,quests,btr,bosses,null];
}
