// CMS уже выдаёт обогащённые квесты: вспомогательные файлы нужны только автономному снимку.
export async function loadReferenceSources(mode,{dataset,json,extras},tab='items'){
  const quests=dataset('quests-'+mode,`data/guide/quests-${mode}.json`,{view:tab==='needed'?'catalog':'lookup'});
  return Promise.all([
    dataset('reference-'+mode,`data/guide/reference-${mode}.json`),
    dataset('items-'+mode,`data/guide/items-${mode}.json`),
    quests,tab==='btr'?json('data/guide/btr.json'):{maps:{}},tab==='bosses'?json('data/guide/bosses.json'):{profiles:[]},
    quests.then(bundle=>bundle.cms?null:extras()),
  ]);
}
