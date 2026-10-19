import {profileStore} from './profile-store.mjs?v=loading-20261018';
import {el, action} from './guide-common.mjs?v=documents-access-20261020';
/** Reflect the adapter's real local/server result; retry sends the latest value. */
export class QuestSaveFeedback {
  constructor(key) {
    this.key = key; this.event = null; this.undoAction = null;
    this.unsubscribe = profileStore.subscribe(event => {
      if (event.key !== this.key() || event.owner !== profileStore.profile.id) return;
      this.event = event; this.render();
    });
  }
  mount(root) { this.root = root; root.setAttribute('role', 'status'); root.setAttribute('aria-live', 'polite'); this.render(); }
  render() {
    if (!this.root) return;
    const state = this.event?.key === this.key() ? this.event.state : 'idle';
    const text = {idle: 'Отметки сохраняются в вашем профиле', saving: 'Сохраняем…', saved: 'Сохранено', error: 'Не удалось сохранить'}[state];
    this.root.dataset.state = state; this.root.replaceChildren(el('span', text));
    if (state === 'saved') this.root.append(el('small', this.event.scope === 'server' ? 'В аккаунте' : 'В этом браузере'));
    if (state === 'error') this.root.append(action('Повторить', () => profileStore.retry(this.key()), 'text-button quest-save-retry'));
    if (this.undoAction) {
      this.root.append(el('span', this.undoAction.message), action('Отменить', () => {
        const undo = this.undoAction; this.undoAction = null; undo.run(); this.render();
      }, 'text-button quest-undo'));
    }
  }
  offerUndo(message, run) { this.undoAction = {message, run}; this.render(); }
  clearUndo() { this.undoAction = null; this.render(); }
}
