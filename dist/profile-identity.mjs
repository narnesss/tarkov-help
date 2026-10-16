// Изменение отображения не переключает владельца прогресса и не создаёт гостевую сессию.
export function applyPlayerProfile(store,response){
  if(!store.server||response?.profile?.id!==store.profile.id||response.profile.authenticated!==true||typeof response.profile.name!=='string')throw new Error('Сессия изменилась. Обнови страницу.');
  store.profile=response.profile;if(response.csrf)store.csrf=response.csrf;
  store.meta.serverProfile=store.profile;store.persistMeta();
}
