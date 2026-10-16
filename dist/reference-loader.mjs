// CMS уже выдаёт обогащённые квесты: вспомогательные файлы нужны только автономному снимку.
export async function loadReferenceSources(mode,{dataset,json,extras}){
  const quests=dataset('quests-'+mode,`data/guide/quests-${mode}.json`);
  return Promise.all([
    dataset('reference-'+mode,`data/guide/reference-${mode}.json`),
    dataset('items-'+mode,`data/guide/items-${mode}.json`),
    quests,json('data/guide/btr.json'),json('data/guide/bosses.json'),
    quests.then(bundle=>bundle.cms?null:extras()),
  ]);
}
