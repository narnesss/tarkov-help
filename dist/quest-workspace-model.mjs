/** Navigation groups describe source order, never infer gameplay prerequisites. */
export function objectiveGroups(objectives) {
  const groups = [];
  for (const [index, objective] of objectives.entries()) {
    const context = JSON.stringify([objective.phase || '', objective.branchLabels || []]);
    let group = groups.at(-1);
    if (!group || group.context !== context) {
      group = {id: objective.id, context, start: index + 1, objectives: []}; groups.push(group);
    }
    group.objectives.push(objective); group.end = index + 1;
    group.title = objective.phase === 'terminal' ? 'Финальный рейд' : group.objectives[0].description;
  }
  return groups;
}
export function objectiveWindow(objectives, completed) {
  const done = new Set(completed), remaining = objectives.filter(o => !done.has(o.id));
  return {visible: new Set(objectives.map(o => o.id)), completed: objectives.filter(o => done.has(o.id)), current: remaining[0]?.id, done: objectives.length - remaining.length, total: objectives.length};
}
export function setObjective(completed, id, checked) {
  return checked ? [...new Set([...completed, id])] : completed.filter(value => value !== id);
}
