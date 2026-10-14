import {el} from './guide-common.mjs';

export function questBriefing(task){
 const briefing=task.questContent?.briefing;
 if(!briefing?.paragraphs?.length)return null;
 const panel=el('section','','panel briefing-block');panel.setAttribute('aria-label','Брифинг задания');
 const head=el('div','','briefing-head');
 head.append(el('span',task.questContent.trader||'Основной сюжет','briefing-trader'),el('span',briefing.kind==='summary'?'БРИФИНГ · КРАТКО':'БРИФИНГ','briefing-tag'));panel.append(head);
 for(const paragraph of briefing.paragraphs)panel.append(el('p',paragraph,'briefing-para'));
 if(briefing.kind==='summary')panel.append(el('p','Краткий пересказ. Это не дословная реплика из игры.','briefing-source-note'));
 if(briefing.kind!=='summary'||briefing.original?.length){const note=el('p','','briefing-source-note');note.append(el('span','Материалы Wiki · CC BY-NC-SA 3.0 · '));const credit=el('a','Авторы и условия использования');credit.href='licenses/GUIDE-DATA.txt';note.append(credit);panel.append(note);}
 if(briefing.original?.length){const details=el('details','','briefing-original');details.append(el('summary','Английский оригинал'));for(const paragraph of briefing.original){const p=el('p',paragraph);p.lang='en';details.append(p);}panel.append(details);}
 return panel;
}
