const statNames={accuracy:'Точность',durability:'Прочность',effectiveDistance:'Прицельная дальность',ergonomics:'Эргономика',height:'Высота в ячейках',magazineCapacity:'Вместимость магазина',muzzleVelocity:'Начальная скорость',recoil:'Суммарная отдача',weight:'Масса',width:'Ширина в ячейках'};
const units={durability:'%',effectiveDistance:'м',muzzleVelocity:'м/с',weight:'кг'};
export function questOverview(task,trader,byId=new Map()){
 const result=[`Задание выдаёт ${trader||'торговец'}. ${task.minPlayerLevel?`Минимальный уровень персонажа — ${task.minPlayerLevel}.`:''}`.trim()];
 const previous=(task.taskRequirements||[]).map(r=>byId.get(typeof r.task==='string'?r.task:r.task?.id)?.name).filter(Boolean);
 if(previous.length)result.push('Предыдущие задания: '+previous.join('; ')+'. Их требуемый статус указан в условиях открытия.');
 const types=new Set((task.objectives||[]).filter(o=>!o.optional).map(o=>o.type));
 const names={visit:'посетить указанные места',mark:'установить маяки',plantItem:'оставить предметы',findItem:'найти предметы',giveItem:'передать предметы',findQuestItem:'забрать квестовые предметы',giveQuestItem:'сдать квестовые предметы',shoot:'выполнить боевые условия',extract:'выйти с локации',buildWeapon:'подготовить оружие',skill:'развить навык',traderLevel:'повысить лояльность'};
 const goals=[...types].map(k=>names[k]).filter(Boolean);if(goals.length)result.push('Основная работа: '+goals.join(', ')+'. Точные количества, места и ограничения перечислены отдельно для каждой цели.');
 if((task.objectives||[]).some(o=>o.optional))result.push('Необязательные цели отмечены отдельно и не должны смешиваться с основными условиями.');
 return result;
}
export function objectiveGuide(o){
 const result=[];
 if(o.type==='findQuestItem'||o.type==='giveQuestItem')result.push('Этот предмет хранится в инвентаре заданий. После успешного выхода сдайте его или переместите во внерейдовый инвентарь, прежде чем идти в следующий рейд.');
 if(o.type==='giveItem')result.push('Передача выполняется через окно задания у торговца. При наличии альтернативных предметов проверьте, какая группа принимается для этой цели.');
 if(o.foundInRaid)result.push('Для зачёта нужна отметка «Найдено в рейде». Купленный на барахолке предмет этому условию не соответствует.');
 if(o.type==='shoot')result.push('Зачёт зависит от условий этой цели: типа противника, оружия, экипировки, дистанции и локации. Условия ниже применяются одновременно.');
 if(o.type==='buildWeapon')result.push('Перед сдачей сравните собранное оружие со всеми указанными характеристиками. Запрещённые детали и несовпадение хотя бы одного требования препятствуют зачёту.');
 if(o.type==='mark'||o.type==='plantItem')result.push('Возьмите нужные предметы в рейд и взаимодействуйте с указанным местом установки. Дождитесь завершения действия; дополнительное условие выхода действует только когда оно указано в цели.');
 if(o.type==='visit')result.push('Цель засчитывается при посещении требуемой зоны. Условия выхода и дополнительные действия проверяйте отдельно.');
 return result;
}
export function objectiveConditions(o,skills=[],translate=x=>x){
 const result=[],rule=(r,unit='')=>`${r.compareMethod||'≥'} ${r.value}${unit?' '+unit:''}`;
 if(o.distance?.value>0)result.push('Дистанция: '+rule(o.distance,'м'));
 if(o.bodyParts?.length)result.push('Засчитываются попадания: '+o.bodyParts.map(translate).join(', '));
 if(o.targetNames?.length)result.push('Цели: '+o.targetNames.map(translate).join(', '));
 if(o.shotType&&o.shotType!=='kill')result.push('Тип попадания: '+({headshot:'в голову',hit:'попадание'}[o.shotType]||translate(o.shotType)));
 if(o.timeFromHour!==o.timeUntilHour&&(o.timeFromHour!=null||o.timeUntilHour!=null))result.push(`Время в рейде: ${String(o.timeFromHour??0).padStart(2,'0')}:00–${String(o.timeUntilHour??24).padStart(2,'0')}:00`);
 if(o.exitStatus?.length)result.push('Допустимый результат рейда: '+o.exitStatus.map(translate).join(' / '));
 if(o.exitName)result.push('Нужный выход: '+translate(o.exitName));
 if(o.minDurability>0||o.maxDurability!=null&&o.maxDurability<100)result.push(`Прочность сдаваемого предмета: ${o.minDurability??0}–${o.maxDurability??100}%`);
 for(const [key,r] of Object.entries(o.buildAttributes||{}))if(r?.value>0)result.push((statNames[key]||translate(key))+': '+rule(r,units[key]));
 for(const [key,label] of [['healthEffect','Состояние'],['playerHealthEffect','Ваше состояние'],['enemyHealthEffect','Состояние цели']]){
  const state=o[key];if(!state)continue;const effects=state.effects?.map(translate).join(', ');if(effects)result.push(label+': '+effects+(state.time?.value>0?' · длительность '+rule(state.time,'с'):''));
 }
 if(o.effects?.length)result.push('Эффекты: '+o.effects.map(translate).join(', '));
 if(o.skill)result.push(`Навык: ${skills.find(s=>s.id===o.skill)?.name||translate(o.skill)}${o.level!=null?' · '+(o.compareMethod||'≥')+' '+o.level:''}`);
 if(o.globalVariable)result.push('Есть дополнительное сюжетное условие; точное требование смотрите в описании цели и в игре.');
 return [...new Set(result)];
}
