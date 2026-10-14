/** Content overlays never replace quest/goal identities or saved progress. */
export function applyQuestContent(task, content, mode) {
 const source=content?.quests?.[content?.modes?.[mode]?.[task.id]];
 if(!source)return task;
 const storyBranches=source.rewards?.policy==='ticket-branches'?(task.storyBranches||[]).map(branch=>{
  const rewardsByMode=source.rewards.branches?.[branch.id.split('-')[0]];
  return rewardsByMode?{...branch,rewardsByMode}:branch;
 }):task.storyBranches;
 return {...task,questContent:source,storyBranches,
  experience:task.story?task.experience:source.experience??task.experience,
  gallery:[...(task.gallery||[]),...(source.gallery||[])]};
}
