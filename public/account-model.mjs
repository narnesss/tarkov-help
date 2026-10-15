const list=value=>Array.isArray(value)?value:Object.values(value||{});
const unique=value=>[...new Map(list(value).filter(x=>x?.id).map(x=>[x.id,x])).values()];

/** Сводка считает только существующие элементы каталога выбранного режима. */
export function accountSummary({tasks=[],hideout=[],achievements=[]},progress={},built={},earned=[]){
  const quests=unique(tasks),stations=unique(hideout),awards=unique(achievements);
  const entries=progress?.quests&&typeof progress.quests==='object'?progress.quests:{};
  const complete=quests.filter(t=>entries[t.id]?.status==='complete');
  const active=quests.filter(t=>entries[t.id]?.status==='active');
  const levels=unique(stations.flatMap(s=>list(s.levels).map(l=>({...l,stationName:s.name}))));
  const builtLevels=levels.filter(l=>built?.[l.id]===true);
  const earnedIds=new Set(Array.isArray(earned)?earned:[]),unlocked=awards.filter(a=>earnedIds.has(a.id));
  return {level:Math.max(1,Math.min(100,Math.floor(Number(progress?.level)||1))),
    quests:{done:complete.length,total:quests.length,active},
    hideout:{done:builtLevels.length,total:levels.length,unlocked:builtLevels},
    achievements:{done:unlocked.length,total:awards.length,unlocked}};
}
