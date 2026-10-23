export function discardPointDraft(points, saved, pending, id) {
  const index = points.findIndex(point => point.id === id);
  if (index < 0) return null;
  const original = saved.find(point => point.id === id);
  const restored = original ? structuredClone(original) : null;
  if (restored) points[index] = restored;
  else points.splice(index, 1);
  pending.delete(id);
  return restored;
}
