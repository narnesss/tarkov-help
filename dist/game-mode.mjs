/** Explicit links override the current profile; first visits start in PVE. */
export const DEFAULT_GAME_MODE = 'pve';
export function resolveGameMode(requested, saved) {
  const valid = value => value === 'pve' || value === 'regular';
  return valid(requested) ? requested : valid(saved) ? saved : DEFAULT_GAME_MODE;
}
