import {el} from './guide-common.mjs?v=page-content-20261017';

export function questBriefing(task){
 const briefing=task.questContent?.briefing;
 const walkthrough=task.questContent?.walkthrough;
 if(!briefing?.paragraphs?.length&&!walkthrough?.paragraphs?.length)return null;
 const panel=el('section','','panel briefing-block');panel.setAttribute('aria-label','Брифинг задания');
 if(briefing?.paragraphs?.length){
 const head=el('div','','briefing-head');
 head.append(el('span',task.questContent.trader||'Основной сюжет','briefing-trader'),el('span',briefing.kind==='summary'?'БРИФИНГ · КРАТКО':'БРИФИНГ','briefing-tag'));panel.append(head);
 for(const paragraph of briefing.paragraphs)panel.append(el('p',paragraph,'briefing-para'));
 if(briefing.kind==='summary')panel.append(el('p','Краткий пересказ. Это не дословная реплика из игры.','briefing-source-note'));
 if(briefing.kind!=='summary'||briefing.original?.length){const note=el('p','','briefing-source-note');note.append(el('span','Материалы Wiki · CC BY-NC-SA 3.0 · '));const credit=el('a','Авторы и условия использования');credit.href='licenses/GUIDE-DATA.txt';note.append(credit);panel.append(note);}
 if(briefing.original?.length){const details=el('details','','briefing-original');details.append(el('summary','Английский оригинал'));for(const paragraph of briefing.original){const p=el('p',paragraph);p.lang='en';details.append(p);}panel.append(details);}
 }
 if(walkthrough?.paragraphs?.length){
  panel.append(el('h3','Прохождение'));
  for(const paragraph of walkthrough.paragraphs)panel.append(el('p',paragraph,'briefing-para'));
  if(typeof walkthrough.sourceUrl==='string'&&/^https:\/\/escapefromtarkov\.fandom\.com\//.test(walkthrough.sourceUrl)){
   const note=el('p','','briefing-source-note'),link=el('a','Источник прохождения · Escape from Tarkov Wiki');link.href=walkthrough.sourceUrl;link.target='_blank';link.rel='noopener';note.append(link);panel.append(note);
  }
 }
 return panel;
}
