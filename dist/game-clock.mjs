const DAY = 86_400_000;
// Формула часов EFT: UTC × 7 + 3 часа; второй вариант рейда отстоит на 12 часов.
export function gameTimes(now = Date.now()) {
  const first = ((Number(now) % DAY) * 7 + 10_800_000) % DAY;
  const format = value => `${String(Math.floor(value / 3_600_000)).padStart(2, '0')}:${String(Math.floor(value / 60_000) % 60).padStart(2, '0')}`;
  return [format((first + DAY) % DAY), format((first + DAY / 2 + DAY) % DAY)];
}

export function mountGameClocks(root = document) {
  const clocks = [...root.querySelectorAll('[data-game-clock]')];
  if (!clocks.length) return;
  let anchor=null,timer,attempt=0;
  const update = () => {
    if(!anchor)return;
    const times = gameTimes(anchor.unixMilliseconds + performance.now() - anchor.performanceAt);
    for (const clock of clocks) {
      clock.querySelectorAll('[data-game-time]').forEach((node,index)=>{node.textContent=times[index];});
      clock.setAttribute('aria-label','Внутриигровое время: '+times[0]+' и '+times[1]+'.');
    }
  };
  const sync = async () => {
    clearInterval(timer);const ticket=++attempt,start=performance.now();
    try{
      const response=await fetch('/api/content/time',{credentials:'omit',cache:'no-store',signal:AbortSignal.timeout(5000)});
      if(!response.ok)throw Error('Время недоступно');
      const data=await response.json();
      if(!Number.isFinite(data.unixMilliseconds)||data.unixMilliseconds<0||data.scale!==7||data.offsetMilliseconds!==10800000)throw Error('Некорректное время');
      if(ticket!==attempt||document.hidden)return;
      anchor={unixMilliseconds:data.unixMilliseconds,performanceAt:(start+performance.now())/2};
    }catch{/* До первой синхронизации оставляем --:--; прежняя серверная опора продолжает идти. */}
    if(ticket!==attempt||document.hidden)return;
    update();timer=setInterval(update,1000);
  };
  document.addEventListener('visibilitychange',()=>{
    if(document.hidden){attempt++;clearInterval(timer);}else sync();
  });
  sync();
}
if (typeof document !== 'undefined') mountGameClocks();
