/** Personal state lives behind one adapter. Public API caches never contain progress. */
export const PERSONAL_KEY = /^(?:kord-atlas-(?:progress-v1|view-v1|raid-progress-v1|raid-view-v1|game-mode|quests-(?:regular|pve)-v1|quest-view-(?:regular|pve)-v1|reference-view-(?:regular|pve)-v1)|kord-(?:hideout|achievements)-(?:regular|pve)|wiki-ui-v1)$/;
const META = 'eft-wiki:profiles:v1';
const equal = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const clone = value => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
const uuid = () => globalThis.crypto?.randomUUID?.() || `local-${Date.now()}-${Math.random().toString(36).slice(2)}`;
const browserStorage = () => { try { return globalThis.localStorage; } catch { return undefined; } };
const dangerous = new Set(['__proto__', 'constructor', 'prototype']);
export function validPersonalValue(key, value) {
  if (!PERSONAL_KEY.test(key)) return false;
  if (key === 'kord-atlas-game-mode') return ['regular', 'pve'].includes(value);
  if (!value || typeof value !== 'object') return false;
  const safe = (node, depth = 0) => depth <= 60 && (node === null || typeof node !== 'object' || Object.entries(node).every(([k, v]) => !dangerous.has(k) && safe(v, depth + 1)));
  try { return JSON.stringify(value).length < 500000 && safe(value); } catch { return false; }
}

/** Merge independent edits; a deliberate local change wins a same-field conflict. */
export function mergeState(base, local, remote) {
  if (equal(local, base)) return clone(remote);
  if (equal(remote, base) || equal(local, remote)) return clone(local);
  if ([local, remote].every(Array.isArray) && [...local, ...remote, ...(Array.isArray(base) ? base : [])].every(v => typeof v === 'string')) {
    const before = new Set(Array.isArray(base) ? base : []), after = new Set(local);
    return [...new Set([...remote.filter(v => !before.has(v) || after.has(v)), ...local.filter(v => !before.has(v))])];
  }
  if (local && remote && typeof local === 'object' && typeof remote === 'object' && !Array.isArray(local) && !Array.isArray(remote)) {
    const result = {};
    for (const key of new Set([...Object.keys(base || {}), ...Object.keys(local), ...Object.keys(remote)])) {
      if (dangerous.has(key)) continue;
      const value = mergeState(base?.[key], local[key], remote[key]);
      if (value !== undefined) result[key] = value;
    }
    return result;
  }
  return clone(local);
}

export class ProfileStore {
  constructor({storage = browserStorage(), request = globalThis.fetch?.bind(globalThis), notify = () => {}, delay = 500} = {}) {
    this.storage = storage; this.request = request; this.notify = notify; this.delay = delay;
    this.listeners = new Set(); this.saveStates = new Map();
    this.memory = new Map(); this.pending = new Set(); this.server = false; this.csrf = ''; this.syncing = null; this.sessionConfirmed=false;
    const stored = this.raw(META);
    this.meta = stored && typeof stored === 'object' && !Array.isArray(stored) ? stored : {profiles: [], active: null, migrated: false};
    this.meta.profiles = Array.isArray(this.meta.profiles) ? this.meta.profiles.filter(p => p && typeof p.id === 'string' && /^[a-zA-Z0-9-]+$/.test(p.id) && typeof p.name === 'string') : [];
    if (!this.meta.profiles?.length) { const profile = {id: uuid(), name: 'Мой профиль'}; this.meta.profiles = [profile]; this.meta.active = profile.id; }
    this.profile = this.meta.profiles.find(p => p.id === this.meta.active) || this.meta.profiles[0];
    this.persistMeta(); this.migrateLegacy();
  }

  raw(key) { try { return JSON.parse(this.storage?.getItem(key) ?? 'null'); } catch { return null; } }
  setRaw(key, value) { try { this.storage?.setItem(key, JSON.stringify(value)); return !!this.storage; } catch { return false; } }
  persistMeta() { this.setRaw(META, this.meta); }
  key(key, id = this.profile.id) { return `eft-wiki:v1:profile:${id}:data:${key}`; }
  record(key) { return this.memory.get(key) || this.raw(this.key(key)) || {value: null, version: 0, base: null, dirty: false}; }
  saveRecord(key, record) { this.memory.set(key, record); return this.setRaw(this.key(key), record); }
  read(key) { if (!PERSONAL_KEY.test(key)) return null; const record = this.record(key); this.memory.set(key, record); return clone(record.value); }

  keys(id = this.profile.id) {
    const prefix = `eft-wiki:v1:profile:${id}:data:`, keys = new Set(id === this.profile.id ? this.memory.keys() : []);
    try { for (let i = 0; i < this.storage.length; i++) { const key = this.storage.key(i); if (key?.startsWith(prefix)) keys.add(key.slice(prefix.length)); } } catch { /* Memory-only storage remains usable. */ }
    return [...keys].filter(k => PERSONAL_KEY.test(k));
  }

  migrateLegacy() {
    if (this.meta.migrated) return;
    try {
      for (const key of Array.from({length: this.storage.length}, (_, i) => this.storage.key(i)).filter(k => PERSONAL_KEY.test(k))) {
        const value = this.raw(key); if (value !== null) this.saveRecord(key, {value, base: null, version: 0, dirty: false});
      }
      this.meta.migrated = true; this.persistMeta();
    } catch { /* No shared fallback: a new profile must never inherit another person's data. */ }
  }

  subscribe(listener) { this.listeners.add(listener); return () => this.listeners.delete(listener); }
  signal(key, state, scope = this.server ? 'server' : 'browser') {
    const event = {key, state, scope, owner: this.profile.id}; this.saveStates.set(key, event);
    for (const listener of this.listeners) listener(event);
  }
  async retry(key) {
    const record = this.record(key);
    if (!record.value) return true;
    if (this.server) { this.pending.add(key); this.signal(key, 'saving'); return this.flush(); }
    const saved = this.saveRecord(key, record); this.signal(key, saved ? 'saved' : 'error'); return saved;
  }
  write(key, value) {
    if (!validPersonalValue(key, value)) throw new TypeError('Неверный формат данных профиля.');
    const old = this.record(key); if (equal(old.value, value)) { const persisted = equal(this.raw(this.key(key))?.value, value); this.signal(key, this.server ? this.pending.has(key) ? 'saving' : 'saved' : persisted ? 'saved' : 'error'); return persisted; }
    const latest = this.raw(this.key(key));
    const merged = latest && !equal(old.value, latest.value) ? mergeState(old.value, value, latest.value) : value;
    const saved = this.saveRecord(key, {...(latest || old), value: clone(merged), dirty: this.server});
    if (this.server) { this.pending.add(key); this.schedule(); }
    this.signal(key, this.server ? 'saving' : saved ? 'saved' : 'error');
    return saved;
  }

  create(name) {
    const clean = String(name).trim().slice(0, 40); if (!clean) throw new Error('Введите имя профиля.');
    const profile = {id: uuid(), name: clean}; this.meta.profiles.push(profile); this.persistMeta(); return profile;
  }
  activate(id) {
    const profile = this.meta.profiles.find(p => p.id === id); if (!profile) throw new Error('Профиль не найден.');
    if (this.server) throw new Error('Войдите в нужный аккаунт.');
    this.profile = profile; this.meta.active = id; this.memory.clear(); this.pending.clear(); this.persistMeta();
  }
  export() { return {schema: 'eft-wiki-profile', version: 1, name: this.profile.name, state: Object.fromEntries(this.keys().map(k => [k, this.read(k)]).filter(([key,value])=>validPersonalValue(key,value)))}; }
  import(data) {
    if (data?.schema !== 'eft-wiki-profile' || data.version !== 1 || !data.state || typeof data.state !== 'object') throw new Error('Это не файл профиля сайта.');
    const entries = Object.entries(data.state);
    if (!entries.every(([key, value]) => validPersonalValue(key, value))) throw new Error('Файл содержит некорректные данные профиля.');
    for (const [key, value] of entries) this.write(key, value);
  }

  async api(path, options = {}) {
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await this.request(path, {credentials: 'same-origin', signal: controller.signal, ...options, headers: {'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf, ...options.headers}});
      const data = await response.json();
      if (!response.ok) { const error = new Error(data.error || data.message || 'Сервер недоступен.'); error.status = response.status; error.data = data; throw error; }
      return data;
    } finally { clearTimeout(timeout); }
  }

  async initialize(runtime = 'static') {
    if (runtime !== 'php') return;
    this.server = true;
    this.sessionConfirmed=false;
    const session = await this.api('/api/session', {headers:{'X-Wiki-Include-State':'1'}}); this.csrf = session.csrf; this.sessionConfirmed=true;
    const previousGuest = this.meta.serverProfile && !this.meta.serverProfile.authenticated ? this.meta.serverProfile : null;
    const localGuest = previousGuest && previousGuest.id !== session.profile.id && !session.profile.authenticated
      ? {state: Object.fromEntries(this.keys(previousGuest.id).map(k => [k, this.raw(this.key(k, previousGuest.id))?.value]))}
      : this.export();
    this.profile = session.profile; this.meta.serverProfile=this.profile; this.persistMeta(); this.memory.clear();
    const {state} = session.state && typeof session.state==='object' && (!Array.isArray(session.state)||session.state.length===0)
      ? session : await this.api('/api/state');
    // Only the first anonymous server profile imports the existing browser profile.
    if (!session.profile.authenticated && (!this.meta.serverMigrated || previousGuest && previousGuest.id !== session.profile.id) && !Object.keys(state).length) {
      for (const [key, value] of Object.entries(localGuest.state)) this.write(key, value);
      this.meta.serverMigrated = true;
    }
    this.meta.serverProfile = this.profile; this.persistMeta();
    for (const key of new Set([...Object.keys(state), ...this.keys()])) {
      const local = this.record(key), remote = state[key] || {value: null, version: 0};
      const value = local.dirty ? mergeState(local.base, local.value, remote.value) : remote.value;
      this.saveRecord(key, {value, base: clone(remote.value), version: remote.version, dirty: !equal(value, remote.value)});
      if (!equal(value, remote.value)) this.pending.add(key);
    }
    if (this.pending.size) this.schedule();
  }

  schedule() { clearTimeout(this.timer); this.timer = setTimeout(() => this.flush(), this.delay); }
  async flush() {
    if (!this.server || !this.pending.size) return true;
    if (this.syncing) { await this.syncing; return this.pending.size ? this.flush() : true; }
    const owner = this.profile.id;
    this.syncing = (async () => {
      for (const key of [...this.pending]) {
        let record = this.record(key);
        const sentLocal = clone(record.value); this.signal(key, 'saving');
        try {
          let reply;
          for (let attempt = 0; attempt < 3; attempt++) {
            try { reply = await this.api('/api/state/' + encodeURIComponent(key), {method: 'PUT', body: JSON.stringify({value: record.value, version: record.version})}); break; }
            catch (error) {
              if (error.status !== 409 || !error.data.current) throw error;
              const current = error.data.current;
              record = {...record, value: mergeState(record.base, record.value, current.value), base: current.value, version: current.version};
            }
          }
          if (!reply) throw new Error('Профиль меняется в другой вкладке. Повторим сохранение позже.');
          if (this.profile.id !== owner) return false;
          const latest = this.record(key), value = equal(latest.value, sentLocal) ? reply.value : mergeState(sentLocal, latest.value, reply.value);
          const dirty = !equal(value, reply.value);
          this.saveRecord(key, {value, base: clone(reply.value), version: reply.version, dirty});
          if (!dirty) this.pending.delete(key);
          this.signal(key, dirty ? 'saving' : 'saved');
        } catch (error) { if (this.profile.id === owner) for (const pending of this.pending) this.signal(pending, 'error'); this.notify('Не удалось синхронизировать профиль. ' + error.message); return false; }
      }
      return true;
    })();
    let result;
    try { result = await this.syncing; return result; } finally { this.syncing = null; if (result && this.pending.size) this.schedule(); }
  }

  async authenticate(action, credentials = {}) {
    if (!(await this.flush())) throw new Error('Сначала восстановите соединение, чтобы сохранить последние изменения.');
    await this.api('/api/auth/' + action, {method: 'POST', body: JSON.stringify(credentials)});
    // Other open tabs must reload before reading or writing the newly selected identity.
    this.setRaw('eft-wiki:identity-change', {id: uuid(), at: Date.now()});
  }

  onStorage(event) {
    if (event.key === 'eft-wiki:identity-change' || event.key === META && this.raw(META)?.active !== this.meta.active) return {reload: true};
    const prefix = this.key(''); if (!event.key?.startsWith(prefix)) return null;
    const key = event.key.slice(prefix.length); if (!PERSONAL_KEY.test(key)) return null;
    this.memory.delete(key); if (this.server && this.record(key).dirty) { this.pending.add(key); this.schedule(); }
    return {key};
  }
}

export const profileStore = new ProfileStore({notify: text => globalThis.dispatchEvent?.(new CustomEvent('wiki:notice', {detail: text}))});
export const readPersonal = key => profileStore.read(key);
export const writePersonal = (key, value) => profileStore.write(key, value);
