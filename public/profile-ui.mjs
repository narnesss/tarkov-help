import {profileStore} from './profile-store.mjs?v=page-content-20261017';
import {siteHref} from './site-routes.mjs?v=page-content-20261017';
import {renderProfileNavigation} from './profile-navigation.mjs?v=page-content-20261017';

const node = (tag, text, className = '') => { const n = document.createElement(tag); if (text) n.textContent = text; n.className = className; return n; };
function button(text, handler, className = 'button') { const b = node('button', text, className); b.type = 'button'; b.onclick = handler; return b; }

export function mountProfile() {
  const trigger = document.getElementById('profile-button'); if (!trigger) return;
  const dialog = node('dialog', null, 'dialog profile-dialog'); dialog.id = 'profile-dialog'; dialog.setAttribute('aria-labelledby', 'profile-title');
  const header = node('div', null, 'dialog-heading'), title = node('h2', 'Личный профиль'); title.id = 'profile-title';
  const close = button('×', () => dialog.close(), 'icon-button'); close.setAttribute('aria-label', 'Закрыть профиль'); header.append(title, close); dialog.append(header);
  const content = node('div'), status = node('p', '', 'profile-status'); status.setAttribute('role', 'status'); dialog.append(content, status); document.body.append(dialog);
  const notify = text => { status.textContent = text; };
  globalThis.addEventListener('wiki:notice', e => { notify(e.detail); trigger.dataset.unsynced = 'true'; trigger.title = e.detail; });
  const reload = () => location.reload();
  async function run(fn) { status.textContent = ''; try { await fn(); } catch (error) { notify(error.message); } }
  function render() {
    content.replaceChildren(); renderProfileNavigation(profileStore,trigger,document.querySelectorAll('[data-player-guest]'));
    content.append(node('p', profileStore.server ? profileStore.profile.authenticated ? 'Отметки, свёрнутые цели, заметки и фильтры сохраняются в вашем аккаунте и синхронизируются между устройствами.' : 'Отметки, свёрнутые цели, заметки и фильтры принадлежат только этому профилю. Войдите в аккаунт для синхронизации между устройствами.' : 'Отметки, свёрнутые цели, заметки и фильтры сохраняются отдельно для каждого профиля в этом браузере. На другом устройстве можно загрузить файл профиля.'));
    if (!profileStore.server) {
      const label = node('label', 'Активный профиль', 'profile-field'), select = node('select');
      for (const p of profileStore.meta.profiles) { const option = node('option', p.name); option.value = p.id; select.append(option); }
      select.value = profileStore.profile.id; select.onchange = () => { profileStore.activate(select.value); reload(); }; label.append(select); content.append(label);
      const form = node('form', null, 'profile-form'), name = node('input'); name.placeholder = 'Имя нового профиля'; name.setAttribute('aria-label', 'Имя нового профиля'); name.maxLength = 40; name.required = true;
      const submit = node('button', 'Создать профиль', 'button'); submit.type = 'submit'; form.append(name, submit);
      form.onsubmit = e => { e.preventDefault(); run(() => { const p = profileStore.create(name.value); profileStore.activate(p.id); reload(); }); }; content.append(form);
    } else if (profileStore.profile.authenticated) {
      content.append(node('p', 'Выполнен вход: ' + profileStore.profile.name, 'profile-current'), button('Выйти из аккаунта', () => run(async () => { await profileStore.authenticate('logout'); reload(); })));
    } else {
      const form = node('form', null, 'profile-auth'), userLabel = node('label', 'Логин', 'profile-field'), user = node('input'); user.name = 'username'; user.autocomplete = 'username'; user.required = true; user.maxLength = 32; userLabel.append(user);
      const passwordLabel = node('label', 'Пароль', 'profile-field'), password = node('input'); password.type = 'password'; password.name = 'password'; password.autocomplete = 'current-password'; password.required = true; passwordLabel.append(password);
      const actions = node('div', null, 'guide-button-row'), login = node('button', 'Войти', 'button primary'); login.type = 'submit';
      const register = button('Создать аккаунт', () => { location.assign(siteHref('account.html?view=register')); });
      form.append(node('p','Для нового аккаунта: минимум 12 символов (лучше 16+), заглавная и строчная латинские буквы, цифра и спецсимвол. Придумай отдельный пароль без популярных слов, дат и последовательностей.','profile-help'));
      actions.append(login, register); form.append(userLabel, passwordLabel, node('p', 'Новый аккаунт создаётся с пустым прогрессом. Чтобы перенести отметки гостя, сначала скачайте профиль и затем загрузите его после входа.', 'profile-help'), actions);
      form.onsubmit = e => { e.preventDefault(); run(async () => { await profileStore.authenticate('login', {username: user.value, password: password.value}); reload(); }); }; content.append(form);
    }
    const actions = node('div', null, 'profile-actions'), input = node('input'); input.type = 'file'; input.accept = '.json,application/json'; input.hidden = true;
    actions.append(button('Скачать весь профиль', () => { const url = URL.createObjectURL(new Blob([JSON.stringify(profileStore.export(), null, 2)], {type: 'application/json'})); const a = node('a'); a.href = url; a.download = 'eft-wiki-profile.json'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); }), button('Загрузить профиль', () => input.click()), input);
    input.onchange = () => run(async () => { const file = input.files?.[0]; if (!file) return; if (file.size > 5e6) throw new Error('Файл профиля слишком большой.'); profileStore.import(JSON.parse(await file.text())); if (await profileStore.flush()) reload(); else notify('Данные сохранены в этом браузере. Синхронизация повторится после восстановления соединения.'); });
    content.append(actions);
    if (profileStore.server) content.append(button('Синхронизировать сейчас', () => run(async () => {const saved=await profileStore.flush();if(saved){delete trigger.dataset.unsynced;trigger.removeAttribute('title');}notify(saved ? 'Изменения сохранены на сервере.' : 'Есть несинхронизированные изменения.');})));
  }
  globalThis.addEventListener('wiki:profile',render);
  globalThis.addEventListener('wiki:open-profile', () => { render(); if(!dialog.open)dialog.showModal(); }); render();
}
