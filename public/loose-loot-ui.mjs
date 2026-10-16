import {el,num} from './guide-common.mjs?v=release-20261016';
import {looseItemOptions,filterLooseItems} from './loose-loot-model.mjs';

/** Item-level controls keep search separate from the map's global POI search. */
export class LooseLootFilter{
  constructor(root,{catalog,preferences,image,onChange,onPersist}){
    Object.assign(this,{root,catalog,preferences,image,onChange,onPersist});this.rows=[];
    this.search=root.querySelector('#loose-item-search');this.options=root.querySelector('#loose-item-options');
    this.status=root.querySelector('#loose-item-selection');this.count=root.querySelector('#loose-type-count');
    this.search.oninput=()=>{this.preferences.update(this.mode,this.map,{query:this.search.value});this.renderRows();this.onPersist();};
    root.querySelector('#loose-select-all').onclick=()=>{this.preferences.all(this.mode,this.map);this.changed();};
    root.querySelector('#loose-select-none').onclick=()=>{this.preferences.none(this.mode,this.map);this.changed();};
    root.addEventListener('toggle',()=>{if(this.map&&this.preferences.scope(this.mode,this.map).expanded!==root.open){this.preferences.update(this.mode,this.map,{expanded:root.open});this.onPersist();}});
  }
  setContext(mode,map,points){
    this.mode=mode;this.map=map;this.rows=looseItemOptions(points,this.catalog);
    const scope=this.preferences.scope(mode,map);this.search.value=scope.query;this.root.open=scope.expanded;
    this.renderRows();
  }
  renderRows(){
    const selection=this.preferences.selected(this.mode,this.map),rows=filterLooseItems(this.rows,this.search.value);
    this.options.replaceChildren(...rows.map(item=>{
      const label=el('label','','raid-loose-item'),check=el('input');check.type='checkbox';check.dataset.looseItem=item.id;
      check.checked=selection===null||selection.has(item.id);check.onchange=()=>{this.preferences.toggle(this.mode,this.map,item.id,check.checked,this.rows.map(r=>r.id));this.changed(false);};
      label.append(check,this.image(item,'loose-filter-icon'),el('span',item.name,'loose-item-name'),el('span',num(item.count),'category-count'));
      return label;
    }));
    if(!rows.length)this.options.append(el('p',this.rows.length?'Ничего не найдено. Измените название предмета.':'В снимке этой локации нет предметов вне контейнеров.','raid-empty'));
    this.updateSelection();
  }
  updateSelection(){
    const selection=this.preferences.selected(this.mode,this.map),chosen=this.rows.filter(r=>selection===null||selection.has(r.id)).length;
    this.count.textContent=`${num(chosen)} / ${num(this.rows.length)}`;
    this.status.textContent=`Выбрано ${num(chosen)} из ${num(this.rows.length)} предметов. Число справа — возможные места появления на этой локации.`;
  }
  changed(rebuild=true){
    if(rebuild)this.renderRows();else this.updateSelection();
    this.onChange();this.onPersist();
  }
}
