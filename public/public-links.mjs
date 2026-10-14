export const PUBLIC_WIKI='https://escapefromtarkov.fandom.com/ru/wiki/Escape_from_Tarkov_Wiki';
export const PUBLIC_HELP='https://tarkov.help/ru';
export function publicAttribution(text){return String(text||'').replace(/Изображение:\s*NORVINSK\.SYS\.\s*/gi,'').replace(/Каталог и иконки — tarkov\.dev\.?/gi,'').replace(/;\s*исходная иллюстрация — eftarkov\.com\.?/gi,'').replace(/сохранённого снимка tarkov\.dev/gi,'сохранённого снимка').replace(/В текущем наборе Tarkov Navigator/gi,'В текущем наборе').replace(/https?:\/\/[^\s<>]+/gi,url=>publicLinkURL(url)?url:'').trim();}

/** Public navigation only; API and image loading retain their original service URLs. */
export function publicLinkURL(value,base=globalThis.location?.href||'https://wiki.local/'){
 try{
  if(typeof value!=='string'||!value.trim())return '';
  const url=new URL(value,base),local=new URL(base);
  if(!['https:','http:'].includes(url.protocol)||url.username||url.password)return '';
  if(url.origin===local.origin)return url.href;
  if(url.protocol!=='https:'||url.username||url.password||url.port)return '';
  if(url.hostname==='tarkov.help')return /^\/ru(?:\/|$)/.test(url.pathname)?url.href:PUBLIC_HELP;
  if(url.hostname==='escapefromtarkov.fandom.com')return url.pathname.startsWith('/ru/wiki/')?url.href:PUBLIC_WIKI;
  return '';
 }catch{return '';}
}
