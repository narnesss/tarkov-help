// Условия вывода выполняет PHP. Worker вставляет результат до передачи HTML браузеру.
const sessionCookie=request=>request.headers.get('Cookie')?.match(/(?:^|;\s*)eft_session=([^;]*)/)?.[1];
export function navigationAssetRequest(request){
  if(sessionCookie(request)===undefined)return request;
  const headers=new Headers(request.headers);headers.delete('If-None-Match');headers.delete('If-Modified-Since');
  return new Request(request,{headers});
}
export async function playerNavigation(request,response,config){
  if(sessionCookie(request)===undefined||!response.headers.get('Content-Type')?.includes('text/html'))return response;
  const headers=new Headers(response.headers);
  headers.set('Cache-Control','private, no-store');headers.set('Vary',[...new Set((headers.get('Vary')||'').split(',').map(v=>v.trim()).filter(Boolean).concat('Cookie'))].join(', '));
  for(const name of ['ETag','Last-Modified','Content-Length','Content-Encoding'])headers.delete(name);
  if(request.method==='HEAD')return new Response(null,{status:response.status,headers});
  // При сбое PHP не выдаём авторизованному посетителю гостевые кнопки из статического экспорта.
  let html='<a class="button profile-trigger" id="profile-button" href="/account">Личный кабинет</a>';
  if(config){
    try{
      const target=new URL('/api/player-navigation',config.origin);
      target.searchParams.set('page',/^\/account(?:\.html)?\/?$/.test(new URL(request.url).pathname)?'account':'index');
      const result=await fetch(target,{headers:{'X-Wiki-Proxy-Token':config.token,
        'X-Wiki-Client-IP':request.headers.get('CF-Connecting-IP')||'','Cookie':'eft_session='+sessionCookie(request)},
        redirect:'manual',cache:'no-store',signal:AbortSignal.timeout(2000)});
      if(result.ok&&result.headers.get('Content-Type')?.includes('application/json')){
        const data=await result.json();if(typeof data.html==='string'&&data.html.length<4096&&data.html.includes('id="profile-button"'))html=data.html;
      }
    }catch{/* Страница доступна и при временном сбое API; состояние входа не угадываем. */}
  }
  return new HTMLRewriter().on('#profile-button',{element(element){element.replace(html,{html:true});}})
    .on('[data-player-guest]',{element(element){element.remove();}})
    .transform(new Response(response.body,{status:response.status,headers}));
}
