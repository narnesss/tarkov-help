import {siteHref} from './site-routes.mjs?v=release-20261016';
import {action,el,external,image,readStorage,writeStorage,toast} from './guide-common.mjs?v=release-20261016';
import {galleryBlock} from './gallery.mjs?v=release-20261016';
import {BUILD_VIEWS,assemblyFrames,shoppingRows,shoppingText} from './gunsmith-model.mjs';

/** Assembly presentation and personal shopping marks, separate from game goals. */
export class GunsmithView{
 constructor(task,{mode='regular',items={}}={}){
  this.task=task;this.record=task.gunsmith;this.items=items;this.mode=mode;this.key=`kord-atlas-quest-view-${mode}-v1`;
  const saved=readStorage(this.key)?.gunsmith?.[task.id]||{};
  this.build=this.record.builds.find(b=>b.objectiveId===saved.weapon)||this.record.builds[0];this.view=BUILD_VIEWS[saved.view]?saved.view:'shopping';this.checked=new Set(Array.isArray(saved.checked)?saved.checked:[]);
  this.root=el('section','','gunsmith-guide');this.root.append(el('h3','Сборка оружия'),el('p','Пример комплектации из Wiki. Итоговое оружие должно соответствовать всем характеристикам цели задания.','gunsmith-intro'));
  const controls=el('div','','gunsmith-controls');if(this.record.builds.length>1){const label=el('label','Оружие'),select=el('select');select.className='gunsmith-weapon';select.setAttribute('aria-label','Выбор оружия для сборки');for(const b of this.record.builds){const option=el('option',b.weaponName);option.value=b.objectiveId;select.append(option);}select.value=this.build.objectiveId;select.onchange=()=>{this.build=this.record.builds.find(b=>b.objectiveId===select.value);this.save();this.render();};label.append(select);controls.append(label);}
  this.tabs=el('nav','','gunsmith-tabs');this.tabs.setAttribute('aria-label','Представления сборки');this.tabs.setAttribute('role','tablist');for(const [id,label] of Object.entries(BUILD_VIEWS)){const b=action(label,()=>{this.view=id;this.save();this.render();},'');b.dataset.view=id;b.setAttribute('role','tab');b.id='build-tab-'+task.id+'-'+id;b.setAttribute('aria-controls','build-panel-'+task.id);this.tabs.append(b);}controls.append(this.tabs);this.root.append(controls);
  this.tabs.addEventListener('keydown',event=>{const views=Object.keys(BUILD_VIEWS),index=views.indexOf(this.view);if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();this.view=views[event.key==='Home'?0:event.key==='End'?views.length-1:(index+(event.key==='ArrowLeft'?-1:1)+views.length)%views.length];this.save();this.render();this.tabs.querySelector(`[data-view="${this.view}"]`)?.focus();});
  this.panel=el('div','','gunsmith-panel');this.panel.id='build-panel-'+task.id;this.panel.setAttribute('role','tabpanel');this.root.append(this.panel);this.render();
 }
 save(){const prefs=readStorage(this.key)||{};writeStorage(this.key,{...prefs,gunsmith:{...(prefs.gunsmith||{}),[this.task.id]:{weapon:this.build.objectiveId,view:this.view,checked:[...this.checked]}}});}
 render(){
  for(const b of this.tabs.children){b.setAttribute('aria-selected',String(b.dataset.view===this.view));b.tabIndex=b.dataset.view===this.view?0:-1;}
  this.panel.setAttribute('aria-labelledby','build-tab-'+this.task.id+'-'+this.view);this.panel.replaceChildren(el('h4',this.build.weaponName));
  if(this.view==='shopping')this.shopping();else{
   const frames=this.build.views?.[this.view]||[];
   if(frames.length){const all=assemblyFrames(this.record),gallery=galleryBlock(all,{title:BUILD_VIEWS[this.view],association:'objective',contextTitle:this.task.name});for(const [index,button] of [...gallery.querySelectorAll('.gallery-thumb')].entries())button.hidden=!frames.some(f=>f.id===all[index].id);this.panel.append(gallery);}
   else this.panel.append(el('p','В доступном источнике нет подтверждённого изображения этого представления.'));
  }
  for(const note of this.build.notes||[])this.panel.append(el('p',note,'gunsmith-source-note'));
  const source=this.build.shopping?.sourceUrl||this.record.legacySourceUrl||this.record.sourceUrl;if(source)this.panel.append(external('Сборка на Wiki ↗',source));
 }
 shopping(){
  const rows=shoppingRows(this.build,this.items),actions=el('div','','gunsmith-shopping-actions');
  actions.append(action('Копировать список',async()=>{const text=shoppingText(this.build,this.items);try{await navigator.clipboard.writeText(text);toast('Список деталей скопирован');}catch{const field=el('textarea');field.value=text;field.readOnly=true;field.setAttribute('aria-label','Список деталей для копирования');this.panel.prepend(field);field.select?.();}},'button'));
  this.panel.append(actions);const wrap=el('div','','gunsmith-shopping-wrap'),table=el('table','','gunsmith-shopping'),head=el('thead'),tr=el('tr');for(const label of ['Есть','Деталь','Кол-во','Условие']){const th=el('th',label);th.scope='col';tr.append(th);}head.append(tr);table.append(head);const body=el('tbody');
  for(const [index,row] of rows.entries()){
   const tr=el('tr');tr.classList.toggle('is-owned',this.checked.has(row.key));const checkCell=el('td'),check=el('input');check.type='checkbox';check.id='build-part-'+this.task.id+'-'+index;check.checked=this.checked.has(row.key);check.setAttribute('aria-label','Есть: '+row.name);check.onchange=()=>{check.checked?this.checked.add(row.key):this.checked.delete(row.key);tr.classList.toggle('is-owned',check.checked);this.save();};checkCell.append(check);
   const name=el('td');if(row.item){const link=el('a','','guide-item');link.href=siteHref(`reference.html?tab=items&item=${encodeURIComponent(row.item.id)}&mode=${this.mode}`);if(row.item.iconLink)link.append(image(row.item.iconLink));link.append(el('span',row.name));name.append(link);}else name.append(el('span',row.name));
   const condition=row.isBaseWeapon?'Базовое оружие · API':row.source==='tarkov.dev'?'Обязательная деталь · API':row.alternativeGroup?'Один из вариантов'+(row.requiredByApi?' · указан в цели':''):row.requiredByApi?'Указано в цели':'Деталь примера';tr.append(checkCell,name,el('td',String(row.count)),el('td',condition));body.append(tr);
  }table.append(body);wrap.append(table);this.panel.append(wrap);
  if(!rows.length)this.panel.append(el('p','Список деталей не удалось подтвердить. Обязательные предметы смотрите в цели задания.'));
  if(this.build.shopping?.status==='partial-index')this.panel.append(el('p','Часть таблицы Wiki недоступна. Список содержит подтверждённые детали; отсутствие детали в нём не означает, что она не нужна.','gunsmith-source-note'));
  if(rows.some(r=>r.alternativeGroup))this.panel.append(el('p','Строки с отметкой «Один из вариантов» — альтернативы; покупать все варианты одновременно не нужно.','gunsmith-source-note'));
  for(const text of this.build.shopping?.notes||[])this.panel.append(el('p',text,'gunsmith-source-note'));
  this.panel.append(el('p','Отметки «Есть» — ваш личный список покупок. Они сохраняются в профиле отдельно от выполнения задания.','gunsmith-source-note'));
 }
}
