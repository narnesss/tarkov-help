/** Content overlays never replace quest/goal identities or saved progress. */
export function applyQuestContent(task, content, mode) {
 const source=content?.quests?.[content?.modes?.[mode]?.[task.id]];
 if(!source)return task;
 const storyBranches=source.rewards?.policy==='ticket-branches'?(task.storyBranches||[]).map(branch=>{
  const rewardsByMode=source.rewards.branches?.[branch.id.split('-')[0]];
  return rewardsByMode?{...branch,rewardsByMode}:branch;
 }):task.storyBranches;
 return {...task,questContent:source,storyBranches,
  objectives:(task.objectives||[]).map(goal=>{
   const guide=source.objectiveGuides?.[goal.id];if(!guide)return goal;
   return {...goal,...(typeof guide.description==='string'?{description:guide.description}:{}),...(Array.isArray(guide.maps)?{maps:guide.maps}:{})};
  }),
  experience:task.story?task.experience:source.experience??task.experience,
  gallery:[...(task.gallery||[]),...(source.gallery||[])]};
}
