import {normalize} from './model.mjs';

export function findBoss(value,profiles){
 const query=normalize(value||'');
 return (profiles||[]).find(b=>b.id===value||b.slug===value||normalize(b.name)===query);
}
export function bossSpawns(profile,reference){
 const mob=profile.spawnParentMobId||profile.id;
 return (reference?.maps||[]).flatMap(map=>(map.bosses||[]).filter(b=>b.mob===mob).map(b=>({...b,map,viaGroup:mob!==profile.id})));
}
export function bossHealth(profile){
 const parts=(profile.health||[]).filter(p=>p.id&&typeof p.bodyPart==='string'&&Number.isFinite(p.max)&&p.max>0);
 const total=parts.reduce((sum,p)=>sum+p.max,0);
 const expected=['Head','Chest','Stomach','LeftArm','RightArm','LeftLeg','RightLeg'];
 return {parts,total,valid:parts.length===7&&new Set(parts.map(p=>p.id)).size===7&&expected.every(id=>parts.some(p=>p.id===id))&&total===profile.healthTotal};
}
export function relatedBosses(profile,profiles){
 const goons=new Set(['bossKnight','followerBigPipe','followerBirdEye']);
 if(goons.has(profile.id))return profiles.filter(p=>goons.has(p.id)&&p.id!==profile.id);
 const pairs={bossKilla:'bossKillaAgro',bossKillaAgro:'bossKilla',bossTagilla:'bossTagillaAgro',bossTagillaAgro:'bossTagilla',bossWedge:'bossWedgeLab',bossWedgeLab:'bossWedge'};
 return profiles.filter(p=>p.id===pairs[profile.id]);
}
