import {cached} from './guide-common.mjs';
import {marketDay,normalizePrices} from './price-model.mjs';

/** Static Drop refreshes directly once per Moscow day; server builds use a shared cache. */
export async function dailyPrices(mode, options = {}) {
  mode = mode === 'pve' ? 'pve' : 'regular';
  const now = options.now ?? Date.now(), request = options.request ?? fetch, cache = options.cache ?? cached;
  const direct = options.direct ?? (globalThis.document?.querySelector('meta[name="wiki-price-mode"]')?.content === 'direct');
  const previous = await cache('daily-prices-' + mode);
  if (direct && previous?.meta?.day === marketDay(now) && previous.meta.provider === 'tarkov.dev') return previous;
  const abort = new AbortController(), timer = setTimeout(() => abort.abort(), 22000);
  try {
    let value;
    if (direct) {
      const response = await request(`https://json.tarkov.dev/${mode}/items`, {signal: abort.signal, cache: 'no-cache'});
      if (!response.ok) throw new Error('price_source_unavailable');
      const items = normalizePrices(await response.json(), 'tarkov.dev');
      value = {schemaVersion: 1, items, stale: false, meta: {provider: 'tarkov.dev', mode, fetchedAt: new Date(now).toISOString(),
        day: marketDay(now), count: Object.keys(items).length, sourceUpdatedAt: response.headers?.get('last-modified') || null, sourceUrl: 'https://tarkov.dev/api/', timeZone: 'Europe/Moscow', marketConnected: false, updateStrategy: 'browser-daily'}};
    } else {
      const response = await request(`/api/prices?mode=${mode}`, {signal: abort.signal});
      if (!response.ok) throw new Error('price_source_unavailable');
      value = await response.json();
    }
    if (!value?.items || Object.keys(value.items).length < 100 || !value.meta?.fetchedAt) throw new Error('invalid_price_snapshot');
    await cache('daily-prices-' + mode, value); return value;
  } catch (error) {
    if (previous) return {...previous, stale: true, warning: 'Показан последний сохранённый снимок цен.'};
    throw error;
  } finally { clearTimeout(timer); }
}
export function applyPrices(items,snapshot){for(const [id,price] of Object.entries(snapshot.items||{})){const item=items.get(id);if(item)items.set(id,{...item,...price,priceProvider:snapshot.meta.provider});}return items;}
