const idOf = value => typeof value === 'string' ? value : value?.id;
const list = value => Array.isArray(value) ? value : Object.values(value || {});
const nameKey = value => String(value || '').normalize('NFKC').toLowerCase().replace(/ё/g,'е').replace(/\s*\((?:pve|pvp) zone\)/gi,'').replace(/[«»“”".!]/g,'').replace(/\s+/g,' ').trim();

export const rewardLabels = {
 experience:'Опыт', items:'Предметы и деньги', reputation:'Репутация',
 buy:'Открывает покупку', barter:'Открывает бартер', craft:'Открывает создание в убежище',
 skills:'Навыки', achievements:'Достижения', traders:'Открывает торговца',
 dialogue:'Открывает диалог', locations:'Открывает локацию',
 customization:'Внешний вид и оформление', arsenal:'Открывает в Арсенале', other:'Другие награды'
};

/** Match the source name exactly; use the ending icon for the selected game mode. */
export function resolveAchievement(value, achievements, mode='pve') {
 const all=list(achievements), id=idOf(value);
 const exact=all.find(a=>a.id===id); if(exact)return exact;
 const matches=all.filter(a=>nameKey(a.name)===nameKey(value));
 return matches.find(a=>mode==='pve'?/\(PVE ZONE\)/i.test(a.name):!/\(PVE ZONE\)/i.test(a.name)) || matches[0];
}

export function rewardAchievementNames(text) {
 if(!/достижени[ея]/i.test(text))return [];
 const names=[...text.matchAll(/[«“"]([^»”"]+)[»”"]/g)].map(m=>m[1]);
 if(names.length)return names;
 const plain=text.match(/достижени[ея]\s*(?::|—|-)?\s+([^.;]+)|достижение:\s*([^.;]+)/i);
 return plain ? [(plain[1] || plain[2]).trim()] : [];
}

export function rewardLineType(text) {
 if(/(?:бартер|обмен)/i.test(text)&&/(?:откры|разблок)/i.test(text))return 'barter';
 if(/(?:крафт|создание|производств)/i.test(text)&&/(?:откры|разблок)/i.test(text))return 'craft';
 if(/(?:покупк)/i.test(text)&&/(?:откры|разблок)/i.test(text))return 'buy';
 if(/(?:откры|разблок).*арсенал/i.test(text))return 'arsenal';
 if(/достижени[ея]/i.test(text))return 'achievements';
 if(/(?:EXP|опыт)/i.test(text))return 'experience';
 if(/(?:\bRep\b|репутаци)|^(?:Реф|Прапор|Терапевт|Лыжник|Механик|Егерь|Барахольщик|Миротворец|Смотритель)\s*[+-]/i.test(text))return 'reputation';
 if(/(?:откры|разблок).*торговц/i.test(text))return 'traders';
 if(/(?:откры|разблок).*локаци/i.test(text))return 'locations';
 if(/(?:откры|разблок).*(?:Лабораторию|Лабиринт|Ледокол).*выбор/i.test(text))return 'locations';
 if(/(?:навык|сила|выносливость|внимательность|болтовые винтовки|дробовики|легкие пулеметы|марксманские винтовки|пистолеты-пулеметы|штурмовые винтовки)/i.test(text)&&/^\s*[+]/.test(text))return 'skills';
 if(/(?:внешний вид|оформление|костюм|повязка|плакат|потолок|стены|фон главного меню|значок профиля|стиль пола)/i.test(text))return 'customization';
 if(/^(?:Армейский жетон|Бронированный кейс|Записка от|Диктофон)/i.test(text))return 'items';
 if(/^(?:[\d+]|Кейс|Медаль)|(?:рубл|руб\.|евро|долл\.)/i.test(text))return 'items';
 return 'other';
}

export function groupRewardLines(lines) {
 const groups=new Map();
 for(const value of lines || []){
  const text=typeof value==='string'?value:value?.description || value?.text || '';
  if(!text.trim())continue;
  const type=rewardLineType(text);if(!groups.has(type))groups.set(type,[]);groups.get(type).push(text);
 }
 return Object.keys(rewardLabels).filter(type=>groups.has(type)).map(type=>({type,title:rewardLabels[type],lines:groups.get(type)}));
}

/** Trade IDs differ between quest rewards and trader assortments: match explicit quest, trader, item and level. */
export function rewardTradeUnlocks(rewards, taskId, barters=[]) {
 const related=taskId?barters.filter(b=>b.taskUnlock===taskId):[];
 const match=(offer,barter)=>idOf(offer.item)===idOf(barter.offeredItem?.item)&&idOf(offer.trader)===idOf(barter.trader)&&Number(offer.level)===Number(barter.minTraderLevel);
 const buy=[], barter=[...(rewards?.barterUnlock || [])];
 for(const offer of rewards?.offerUnlock || []){
  if(offer.type==='barter'||related.some(b=>match(offer,b)))barter.push(offer);else buy.push(offer);
 }
 for(const b of related){const offer={id:b.id,item:b.offeredItem.item,count:b.offeredItem.count,trader:b.trader,level:b.minTraderLevel};if(!barter.some(o=>match(o,b)))barter.push(offer);}
 return {buy,barter};
}
