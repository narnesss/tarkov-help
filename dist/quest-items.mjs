// The API uses IDs, expanded Item objects and nested arrays of alternatives.
// Keep every alternative; never turn an array into an item with an empty ID.
export function itemReferences(value) {
  if (Array.isArray(value)) return value.flatMap(itemReferences);
  if (typeof value === 'string') return value.trim() && !['undefined', 'null'].includes(value) ? [value] : [];
  if (!value || typeof value !== 'object') return [];
  if (value.item) return itemReferences(value.item);
  if (value.ids) return itemReferences(value.ids);
  if (typeof value.id === 'string' && value.id.trim()) return [value];
  return [];
}

export function resolveQuestItem(ref, items = {}, questItems = []) {
  const id = typeof ref === 'string' ? ref : ref?.id;
  if (!id || ['undefined', 'null'].includes(id)) return null;
  const regular = Array.isArray(items) ? items.find(item => item.id === id) : items[id];
  const special = Array.isArray(questItems) ? questItems.find(item => item.id === id) : questItems[id];
  const item = regular || special || (typeof ref === 'object' ? ref : null);
  return item?.name ? {...item, id} : null;
}
