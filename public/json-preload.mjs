// Предзагрузка расходуется один раз: ручное обновление всегда делает новый запрос.
export function createJsonPreloader(load){
  const pending=new Map();
  return {
    preload(key){
      if(pending.has(key))return;
      if(pending.size>=2)pending.delete(pending.keys().next().value);
      const promise=Promise.resolve().then(()=>load(key));pending.set(key,promise);
      promise.catch(()=>{if(pending.get(key)===promise)pending.delete(key);});
    },
    take(key){const promise=pending.get(key);pending.delete(key);return promise;},
  };
}
