/** Round once in seconds, then carry at 60; never render decimal minutes. */
export function formatDuration(value){
 if(value==null||value===''||!Number.isFinite(Number(value))||Number(value)<0)return 'Время не указано';
 const total=Math.round(Number(value)),hours=Math.floor(total/3600),minutes=Math.floor(total%3600/60),seconds=total%60;
 const pad=n=>String(n).padStart(2,'0');
 return hours?`${hours} ч ${pad(minutes)} мин ${pad(seconds)} с`:`${minutes} мин ${pad(seconds)} с`;
}
export function formatRaidRemaining(value){
 if(value==null||value===''||!Number.isFinite(Number(value)))return 'Время не указано';
 return Number(value)<0?`${formatDuration(Math.abs(Number(value)))} после конца рейда в справочном снимке`:`${formatDuration(value)} до конца рейда`;
}
