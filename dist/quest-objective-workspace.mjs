import {el, action} from './guide-common.mjs?v=release-20261016';
import {objectiveGroups, objectiveWindow} from './quest-workspace-model.mjs';

/** All goals stay in source order; the contents only scroll to a goal. */
export class QuestObjectiveWorkspace {
  constructor({root, objectives, completed, preferences = {}, savePreferences, onProgress, story = false}) {
    Object.assign(this, {root, objectives, completed, savePreferences, onProgress, story});
    const {scope, stage, completedOpen, expanded = {}, disclosureVersion, ...disclosure} = preferences;
    // Legacy preferences also recorded automatic completion collapses. Start the
    // new manual-only disclosure policy with every meaningful body open.
    this.preferences = {...disclosure, disclosureVersion: 2, expanded: disclosureVersion === 2 ? expanded : {}};
    this.rows = new Map(); this.groups = objectiveGroups(objectives);
    this.tools = el('div', '', 'objective-workspace-tools');
    this.navigation = el('details', '', 'objective-toc');
    this.navigation.append(el('summary', 'Ветки и этапы · '+this.groups.length));
    const nav = el('nav', '', 'objective-toc-grid'); nav.setAttribute('aria-label', 'Переход к целям задания');
    for (const [index, group] of this.groups.entries()) {
      const button = action('', () => {
        this.navigation.open = false;
        this.focus(group.objectives.find(o => !this.completed().includes(o.id))?.id || group.id);
      }, 'objective-toc-button');
      button.dataset.stage = group.id;
      button.append(el('strong', 'Этап '+String(index+1).padStart(2,'0')), el('span', group.title)); nav.append(button);
    }
    this.navigation.append(nav); this.tools.append(this.navigation); this.tools.hidden = this.groups.length < 2;
    this.active = el('div', '', 'objective-active-list'); root.append(this.tools, this.active);
    this.containers = new Map(); this.stageCounters = new Map();
    for (const [index, group] of this.groups.entries()) {
      const container = el('section', '', 'objective-stage'+(story?' story-objective-stage':'')); container.dataset.stage = group.id;
      const heading = el('h4', '', 'objective-stage-heading');
      if (story) {
        const labels = group.objectives[0].branchLabels || [];
        container.dataset.route = labels.length === 1 ? labels[0] : 'common';
        const title = group.objectives[0].phase === 'terminal' ? 'Финальный рейд' : labels.length === 1 ? 'Ветка «'+labels[0]+'»' : labels.length > 1 ? labels.join(' / ') : 'Цели главы';
        heading.append(el('span','ЭТАП '+String(index+1).padStart(2,'0'),'story-stage-number'), el('span',title,'story-stage-title'));
        const counter=el('span','','story-stage-progress'); heading.append(counter); this.stageCounters.set(group.id,counter);
        // A plain chapter already has the single “Цели задания” heading.
        if (this.groups.length > 1) container.append(heading);
      }
      this.containers.set(group.id, container); this.active.append(container);
    }
  }
  persist(patch) { Object.assign(this.preferences, patch); this.savePreferences(this.preferences); }
  register(objective, row, body, check, doneNote) {
    const content = body.cloneNode(true);
    for (const label of content.querySelectorAll('.story-step-label')) label.remove();
    const hasDetails = Boolean(content.textContent.trim() || content.querySelector('img, video, audio, iframe, canvas, svg'));
    const toggle = hasDetails ? action('', () => {
      const expanded = !body.hidden; this.persist({expanded: {...this.preferences.expanded, [objective.id]: !expanded}});
      body.hidden = expanded; this.syncDisclosure(objective.id);
    }, 'text-button objective-disclosure') : null;
    if (toggle) { toggle.setAttribute('aria-controls', body.id); body.before(toggle); }
    else check.removeAttribute('aria-controls');
    this.rows.set(objective.id, {row, body, check, doneNote, toggle, hasDetails});
    body.hidden = !hasDetails || !(this.preferences.expanded[objective.id] ?? true);
    this.syncDisclosure(objective.id);
  }
  syncDisclosure(id) {
    const {body, check, toggle, doneNote} = this.rows.get(id);
    if (toggle) {
      toggle.textContent = body.hidden ? 'Показать подробности' : 'Скрыть подробности';
      toggle.setAttribute('aria-expanded', String(!body.hidden));
    }
    doneNote.hidden = !check.checked;
  }
  changed(id, checked) {
    const item = this.rows.get(id); if (!item) return;
    item.check.checked = checked;
    this.syncDisclosure(id); this.refresh();
  }
  refresh() {
    const completed = this.completed(), window = objectiveWindow(this.objectives, completed);
    for (const group of this.groups) {
      for (const objective of group.objectives) {
        const item = this.rows.get(objective.id); if (!item) continue;
        item.row.classList.toggle('is-done', completed.includes(objective.id));
        item.row.classList.toggle('is-current', objective.id === window.current);
        item.row.hidden = false;
        const container = this.containers.get(group.id);
        if (item.row.parentNode !== container) container.append(item.row);
      }
      const done=group.objectives.filter(o=>completed.includes(o.id)).length;
      this.stageCounters.get(group.id)?.replaceChildren(document.createTextNode(done+' / '+group.objectives.length));
      this.containers.get(group.id).classList.toggle('is-complete', done===group.objectives.length);
    }
    this.onProgress(window);
  }
  focus(id) {
    const item = this.rows.get(id); if (!item) return;
    if (item.hasDetails) {
      this.persist({expanded: {...this.preferences.expanded, [id]: true}}); item.body.hidden = false; this.syncDisclosure(id);
    }
    item.row.scrollIntoView?.({block: 'center', behavior: globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
    item.check.focus({preventScroll: true});
  }
  continue() {
    const current = objectiveWindow(this.objectives, this.completed()).current;
    if (current) this.focus(current); else this.root.scrollIntoView?.({block:'start'});
  }
}
