const mediaURL = value => typeof value === 'string' ? value.replace(/^(?:\.\/)?assets\//, '/assets/') : value;

const isPatch = value => !!value && typeof value === 'object' && !Array.isArray(value);

/** Новые файловые переводы дополняют CMS; явно заданная редактором картинка приоритетна. */
export function mergeQuestMediaLocalization(bundled, current) {
  const images = {...bundled?.images};
  for (const [source, patch] of Object.entries(current?.images || {})) {
    if (!isPatch(patch)) continue;
    images[source] = {...images[source], ...patch, ...(patch.url || images[source]?.url ? {url: patch.url || images[source].url} : {})};
  }
  return {...bundled, ...current, images};
}

/** Подставляет готовую русскую иллюстрацию, сохраняя источник и привязку к цели. */
export function localizeQuestMedia(frame, localization) {
  if (!frame || typeof frame !== 'object') return frame;
  const candidates = [frame.originalUrl, frame.fullUrl, frame.url].filter(value => typeof value === 'string');
  let source, patch;
  for (const candidate of candidates) {
    if (isPatch(localization?.images?.[candidate])) { source = candidate; patch = localization.images[candidate]; break; }
    try {
      const url = new URL(candidate); url.search = ''; url.hash = '';
      if (isPatch(localization?.images?.[url.href])) { source = candidate; patch = localization.images[url.href]; break; }
    } catch { /* Локальный путь уже переведённой картинки не является внешним источником. */ }
  }
  if (!patch) return frame;
  const translatedURL = mediaURL(patch.url);
  const result = {...frame, ...patch, url: translatedURL || frame.url,
    originalUrl: frame.originalUrl || source, fullUrl: translatedURL || frame.fullUrl || frame.url};
  for (const field of ['id','objectiveIds','association']) if (Object.hasOwn(frame,field)) result[field] = frame[field];
  return result;
}

/** Одинаковая локализация для статического импорта и полной карточки из CMS. */
export function localizeQuestMediaTask(task, localization) {
  if (!task || typeof task !== 'object') return task;
  const frames = values => values.map(frame => localizeQuestMedia(frame, localization));
  const result = {...task};
  if (Array.isArray(task.gallery)) result.gallery = frames(task.gallery);
  if (Array.isArray(task.media?.gallery)) result.media = {...task.media, gallery: frames(task.media.gallery)};
  if (Array.isArray(task.questContent?.gallery)) result.questContent = {...task.questContent, gallery: frames(task.questContent.gallery)};
  if (Array.isArray(task.objectives)) result.objectives = task.objectives.map(goal => Array.isArray(goal.media) ? {...goal, media: frames(goal.media)} : goal);
  return result;
}
