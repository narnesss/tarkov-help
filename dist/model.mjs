export const STORAGE_KEY = 'kord-atlas-progress-v1';

export const FLOOR_NAMES = {
  First_Floor: '1 этаж', Second_Floor: '2 этаж', Third_Floor: '3 этаж',
  Ground_Floor: 'Первый уровень', Basement: 'Подвал',
};

export function normalize(value = '') {
  return String(value).toLocaleLowerCase('ru').replaceAll('ё', 'е').trim();
}

export function filterSpawns(spawns, { mapId, types, query = '', status = 'all', found = new Set(), floor = 'all', allMaps = false }) {
  const words = normalize(query).split(/\s+/).filter(Boolean);
  return spawns.filter(s => {
    if (!allMaps && s.map !== mapId) return false;
    if (types && !types.has(s.doc)) return false;
    if (status === 'remaining' && found.has(s.id)) return false;
    if (status === 'found' && !found.has(s.id)) return false;
    if (floor !== 'all' && (s.floor || 'unknown') !== floor) return false;
    const searchable = normalize([s.caption, s.captionEn, s.docName, s.mapName, s.id, FLOOR_NAMES[s.floor]].filter(Boolean).join(' '));
    return words.every(word => searchable.includes(word));
  });
}

export function sanitizeProgress(value, spawns) {
  if (!value || value.version !== 1 || !Array.isArray(value.found)) throw new Error('Нужен файл прогресса Escape from Tarkov Wiki версии 1.');
  const validIds = new Set(spawns.map(s => s.id));
  const found = [...new Set(value.found.filter(id => typeof id === 'string' && validIds.has(id)))];
  const notes = {};
  if (value.notes && typeof value.notes === 'object' && !Array.isArray(value.notes)) {
    for (const [id, note] of Object.entries(value.notes)) {
      if (validIds.has(id) && typeof note === 'string' && note.trim()) notes[id] = note.slice(0, 2000);
    }
  }
  return { version: 1, found, notes };
}

export function fitTransform(viewWidth, viewHeight, mapWidth, mapHeight, padding = 32) {
  const scale = Math.max(0.01, Math.min((viewWidth - padding * 2) / mapWidth, (viewHeight - padding * 2) / mapHeight));
  return { scale, x: (viewWidth - mapWidth * scale) / 2, y: (viewHeight - mapHeight * scale) / 2 };
}

export function zoomTransform(view, px, py, nextScale) {
  const ratio = nextScale / view.scale;
  return { scale: nextScale, x: px - (px - view.x) * ratio, y: py - (py - view.y) * ratio };
}

export function hasCoordinates(spawn, alternate = false) {
  const [x, y] = alternate ? [spawn.x2, spawn.y2] : [spawn.x, spawn.y];
  return Number.isFinite(x) && Number.isFinite(y) && x >= 0 && x <= 100 && y >= 0 && y <= 100;
}
