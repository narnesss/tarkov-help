import {readPersonal} from './profile-store.mjs?v=page-content-20261017';

// Только подсветка уже выполненного: список и пагинация приходят готовым HTML.
export function showCompletedRequirements() {
  const mode = document.querySelector('[data-important-mode]')?.dataset.importantMode;
  if (!mode) return;
  const quests = readPersonal(`kord-atlas-quests-${mode}-v1`) || {};
  const hideout = readPersonal(`kord-hideout-${mode}`) || {};
  for (const use of document.querySelectorAll('[data-important-id]')) {
    const quest = use.dataset.importantKind === 'quest';
    const state = quests.quests?.[use.dataset.importantId];
    const complete = quest ? state?.status === 'complete' : !!hideout[use.dataset.importantId];
    const label = use.querySelector('.important-complete');
    label.hidden = !complete;
    label.textContent = complete ? (quest ? 'Выполнено' : 'Построено') : '';
  }
}
globalThis.addEventListener('wiki:state', showCompletedRequirements);
globalThis.addEventListener('wiki:profile', showCompletedRequirements);
showCompletedRequirements();
