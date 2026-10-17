<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

use EftWiki\Http\{Request, HttpError};
use EftWiki\Infrastructure\Database;
use EftWiki\Identity\AccountService;
use EftWiki\Profile\StateRepository;
use EftWiki\Prices\{PriceService, CurlTransport};
use EftWiki\View\PageRenderer;
use EftWiki\Application;

final class TestClock implements EftWiki\Support\Clock { public function __construct(public int $time=1791763200) {} public function now(): int { return $this->time; } }
$tests=[]; $assertions=0;
function test(string $name, Closure $body): void { global $tests; $tests[$name]=$body; }
function eq(mixed $actual, mixed $expected, string $message=''): void { global $assertions; $assertions++; if ($actual!==$expected) throw new RuntimeException($message.' Expected '.var_export($expected,true).', received '.var_export($actual,true)); }
function ok(bool $value, string $message=''): void { eq($value,true,$message); }
function error(int $status, Closure $body): void { try {$body();} catch(HttpError $e) {eq($e->status,$status);return;} throw new RuntimeException('Expected HTTP '.$status); }
function fixture(): array {
 $db=new Database(':memory:');$clock=new TestClock();$accounts=new AccountService($db->pdo,$clock);$state=new StateRepository($db->pdo,$clock);
 return [$db,$clock,$accounts,$state];
}
function priceRows(string $provider='tarkov.dev'): array {
 $rows=[];for($i=1;$i<=120;$i++)$rows[]=[$provider==='tarkov-market'?'bsgId':'id'=>str_pad(dechex($i),24,'0',STR_PAD_LEFT),'lastLowPrice'=>100+$i,'avg24hPrice'=>120+$i,'price'=>200+$i,'traderPriceRub'=>90+$i];
 return $provider==='tarkov-market'?$rows:['data'=>['items'=>$rows]];
}
$directories=[];
function temporary(): string { global $directories;$path=sys_get_temp_dir().'/eft-wiki-test-'.bin2hex(random_bytes(5));mkdir($path,0700,true);$directories[]=$path;return $path; }
function clean(string $path): void {foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($path);}

if(function_exists('phpdbg_start_oplog'))phpdbg_start_oplog();

test('Pages routes resolve PHP controllers without accepting unknown paths',function(){
 $renderer=new PageRenderer(dirname(__DIR__,2));
 foreach(['/documents'=>'documents','/documents/customs'=>'documents','/raid/interchange'=>'raid',
  '/quests/the-ticket'=>'quest','/quests/trader/prapor'=>'quests','/quests/story'=>'quests',
  '/reference/items/5447a9cd4bdc2dbd208b4567'=>'reference','/boss/reshala'=>'boss','/quest-map/customs'=>'quest-map-source']as $path=>$page){
  eq($renderer->pageForPath($path),$page);eq($renderer->pageForPath($path.'.html'),$page);
 }
 foreach(['/quests/not-a-real-quest','/raid/no-map','/../bootstrap','/%2e%2e/bootstrap','/reference/items/invalid']as $path)eq($renderer->pageForPath($path),null);
});

test('Request validates JSON type, syntax, size and content type',function(){
 eq((new Request('POST','/', ['content-type'=>'application/json; charset=utf-8'], [], '{"ok":true}'))->json(),['ok'=>true]);
 error(415,fn()=>(new Request('POST','/',[],[],'{}'))->json());
 error(400,fn()=>(new Request('POST','/',['content-type'=>'application/json'],[],'['))->json());
 error(400,fn()=>(new Request('POST','/',['content-type'=>'application/json'],[],'42'))->json());
 error(400,fn()=>(new Request('POST','/',['content-type'=>'application/json'],[],'[1,2]'))->json());
 error(413,fn()=>(new Request('POST','/',['content-type'=>'application/json'],[],str_repeat('a',524289)))->json());
});
test('Web request boundary separates path, query, cookies and canonical headers',function(){
 $before=[$_SERVER,$_GET,$_COOKIE];
 try{$_SERVER=['REQUEST_METHOD'=>'put','REQUEST_URI'=>'/api/state/wiki-ui-v1?owner=bad','CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>'sample','REMOTE_ADDR'=>'192.0.2.1'];$_GET=['owner'=>'bad'];$_COOKIE=['eft_session'=>'sample'];
 $r=Request::globals();eq($r->method,'PUT');eq($r->path,'/api/state/wiki-ui-v1');eq($r->query,['owner'=>'bad']);eq($r->headers['x-csrf-token'],'sample');eq($r->cookies['eft_session'],'sample');eq($r->ip,'192.0.2.1');
 }finally{[$_SERVER,$_GET,$_COOKIE]=$before;}
});
test('Price HTTP transport refuses non-HTTPS protocols',function(){
 try{(new CurlTransport())('file:///etc/hosts',[]);throw new LogicException('must reject');}catch(RuntimeException $e){eq($e->getMessage(),'Price upstream unavailable');}
});
test('SQLite migration is repeatable and data survives a new connection',function(){
 $path=temporary().'/wiki.sqlite';$a=new Database($path);$a->pdo->exec("INSERT INTO users VALUES('test',NULL,NULL,0)");$b=new Database($path);
 eq((int)$b->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),1);eq((int)$b->pdo->query('PRAGMA user_version')->fetchColumn(),1);
 eq((int)$b->pdo->query('PRAGMA foreign_keys')->fetchColumn(),1);
});
test('Anonymous sessions are unique, hashed, revocable and renewed on use',function(){
 [$db,$clock,$accounts]=fixture();$a=$accounts->guest();$b=$accounts->guest();ok($a->userId!==$b->userId);ok($a->token!==$b->token);
 eq($accounts->session($a->token)->userId,$a->userId);eq($accounts->session('bad'),null);eq($accounts->session(null),null);
 eq($db->pdo->query('SELECT token_hash FROM sessions LIMIT 1')->fetchColumn()!==$a->token,true);
 $clock->time+=86400*20;ok($accounts->session($a->token)->expiresAt>$a->expiresAt);$accounts->revoke($a);eq($accounts->session($a->token),null);
 $clock->time+=86400*40;eq($accounts->session($b->token),null);$accounts->cleanup();eq((int)$db->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),0);
});
test('Registration and login preserve account identity without plaintext passwords',function(){
 [$db,,$accounts]=fixture();$a=$accounts->register('Игрок_01','Cedar!Kite9_Moon');$b=$accounts->login('ИГРОК_01','Cedar!Kite9_Moon');
 eq($a->userId,$b->userId);ok($a->token!==$b->token);ok($db->pdo->query('SELECT password_hash FROM users')->fetchColumn()!=='Cedar!Kite9_Moon');
 error(409,fn()=>$accounts->register('игрок_01','Cedar!Kite9_Moon'));error(422,fn()=>$accounts->register('x','short'));
 error(422,fn()=>$accounts->register('validname',str_repeat('x',73)));error(401,fn()=>$accounts->login('игрок_01','incorrect password'));
 error(401,fn()=>$accounts->login('unknown','incorrect password'));
});
test('Rate limits reject excess attempts and expire by time window',function(){
 [,$clock,$accounts]=fixture();for($i=0;$i<8;$i++)$accounts->rateLimit('127.0.0.1','login');error(429,fn()=>$accounts->rateLimit('127.0.0.1','login'));
 $accounts->rateLimit('127.0.0.2','login');$clock->time+=901;$accounts->rateLimit('127.0.0.1','login');$accounts->cleanup();ok(true);
});
test('User state is isolated by session owner and update versions are atomic',function(){
 [,, $accounts,$state]=fixture();$a=$accounts->guest();$b=$accounts->guest();$key='kord-atlas-quests-pve-v1';
 eq($state->put($a->userId,$key,['quests'=>['one'=>['objectives'=>['a']]]],0)['version'],1);eq($state->all($b->userId),[]);
 $value=$state->all($a->userId)[$key]['value'];eq($value['quests']['one']['objectives'],['a']);
 error(409,fn()=>$state->put($a->userId,$key,[],0));error(409,fn()=>$state->put($a->userId,$key,[],8));
 eq($state->put($a->userId,$key,['quests'=>[]],1)['version'],2);eq($state->put($b->userId,$key,['quests'=>['other'=>[]]],0)['version'],1);
 eq($state->all($a->userId)[$key]['value'],['quests'=>[]]);
});
test('State whitelist blocks arbitrary keys, invalid values and oversized writes',function(){
 [,, $accounts,$state]=fixture();$id=$accounts->guest()->userId;
 error(422,fn()=>$state->put($id,'../../admin',[],0));error(422,fn()=>$state->put($id,'kord-atlas-view-v1','oops',0));
 error(422,fn()=>$state->put($id,'kord-atlas-view-v1',[],-1));error(413,fn()=>$state->put($id,'wiki-ui-v1',['a'=>str_repeat('x',500001)],0));
 eq($state->put($id,'kord-atlas-game-mode','pve',0)['value'],'pve');
 foreach(['kord-atlas-quest-view-regular-v1','kord-atlas-reference-view-pve-v1','kord-hideout-regular','kord-achievements-pve'] as $key)ok(StateRepository::validKey($key));
});
test('Price normalization keeps valid IDs, trader offers and missing-price semantics',function(){
 $raw=priceRows();$raw['data']['items'][0]['lastLowPrice']=0;$raw['data']['items'][]=['id'=>'bad'];$items=PriceService::normalize($raw,'tarkov.dev');eq(count($items),120);eq(array_values($items)[0]['lastLowPrice'],null);
 $market=priceRows('tarkov-market');$market[0]['bannedOnFlea']=true;$items=PriceService::normalize($market,'tarkov-market');eq(array_values($items)[0]['lastLowPrice'],null);eq(array_values($items)[1]['marketTraderCurrency'],'RUB');
 try{PriceService::normalize([],'tarkov.dev');throw new LogicException('must reject');}catch(RuntimeException $e){eq($e->getMessage(),'Incomplete price snapshot');}
});
test('Daily price cache refreshes once per Moscow day and keeps previous data on failure',function(){
 $clock=new TestClock(strtotime('2026-10-12T20:59:00Z'));$count=0;$fail=false;$cache=temporary();
 $fetch=function($url,$headers)use(&$count,&$fail){$count++;if($fail)throw new RuntimeException('offline');ok(str_starts_with($url,'https://json.tarkov.dev/'));return priceRows();};
 $service=new PriceService($cache,$clock,$fetch);$a=$service->snapshot('regular');eq($a['meta']['day'],'2026-10-12');eq($a['meta']['count'],120);eq($a['stale'],false);
 $service->snapshot('regular');eq($count,1);$clock->time+=120;$b=$service->snapshot('regular');eq($b['meta']['day'],'2026-10-13');eq($count,2);
 $clock->time+=86400;$fail=true;$c=$service->snapshot('regular');eq($c['stale'],true);eq($c['meta']['day'],'2026-10-13');$service->snapshot('regular');eq($count,3);
 $clock->time+=1801;$fail=false;eq($service->snapshot('regular')['stale'],false);eq($count,4);
 try{$service->snapshot('../escape');throw new LogicException('must reject');}catch(InvalidArgumentException $e){eq($e->getMessage(),'invalid_mode');}
});
test('Market token stays in transport headers, not returned prices',function(){
 $clock=new TestClock();$calls=[];$service=new PriceService(temporary(),$clock,function($url,$headers)use(&$calls){$calls[]=[$url,$headers];return priceRows('tarkov-market');},'private-market-token');
 $a=$service->snapshot('pve');eq($a['meta']['provider'],'tarkov-market');ok(str_contains($calls[0][0],'/pve/items/all?lang=ru'));ok(in_array('x-api-key: private-market-token',$calls[0][1]));ok(!str_contains(json_encode($a),'private-market-token'));
});
test('A failed retry journal never hides or changes the last successful snapshot',function(){
 $clock=new TestClock();$path=temporary();$fail=false;
 $service=new PriceService($path,$clock,function()use(&$fail){if($fail)throw new RuntimeException('offline');return priceRows();});
 $first=$service->snapshot('regular');$bytes=file_get_contents($path.'/regular.json');$clock->time+=86400;$fail=true;
 mkdir($path.'/regular-failure.json'); // A directory at the journal path forces an actual rename failure.
 set_error_handler(static function(int $level,string $message){throw new ErrorException($message,0,$level);});
 try{$last=$service->snapshot('regular');}finally{restore_error_handler();}
 eq($last['stale'],true);eq($last['items'],$first['items']);eq(file_get_contents($path.'/regular.json'),$bytes);
});
test('Daily summary checks both modes and corrupted cache does not break refresh',function(){
 $clock=new TestClock();$path=temporary();file_put_contents($path.'/regular.json','null');$service=new PriceService($path,$clock,fn()=>priceRows());$result=$service->daily();eq($result['ok'],true);eq(count($result['results']),2);
 $failing=new PriceService(temporary(),$clock,fn()=>throw new RuntimeException('offline'));eq($failing->daily()['ok'],false);
});
function appFixture(): array {
 [$db,$clock,$accounts,$states]=fixture();$prices=new PriceService(temporary(),$clock,fn()=>priceRows());$app=new Application($accounts,$states,$prices,new PageRenderer(dirname(__DIR__,2)),str_repeat('s',64),'https://test.example');
 return [$app,$accounts,$states];
}
function req(Application $app,string $method,string $path,?EftWiki\Identity\Session $session=null,array $data=[],?string $csrf=null,array $extra=[]): EftWiki\Http\Response {
 return $app->handle(new Request($method,$path,['content-type'=>'application/json','origin'=>'https://test.example','x-csrf-token'=>$csrf??$session?->csrf(str_repeat('s',64))??'',...$extra],$session?['eft_session'=>$session->token]:[],json_encode((object)$data)));
}
test('HTTP session returns HttpOnly, Secure, SameSite cookie and private response',function(){
 [$app]=appFixture();$response=req($app,'GET','/api/session');eq($response->status,200);foreach(['HttpOnly','Secure','SameSite=Lax']as $word)ok(str_contains($response->headers['Set-Cookie'],$word));eq($response->headers['Cache-Control'],'private, no-store');ok(json_decode($response->body,true)['csrf']!=='');
});
test('API rejects unauthenticated writes, forged CSRF and cross-origin writes',function(){
 [$app,$accounts]=appFixture();$session=$accounts->guest();$data=['value'=>['query'=>'hello'],'version'=>0];
 eq(req($app,'PUT','/api/state/kord-atlas-view-v1',null,$data)->status,401);
 eq(req($app,'PUT','/api/state/kord-atlas-view-v1',$session,$data,'wrong')->status,403);
 eq(req($app,'PUT','/api/state/kord-atlas-view-v1',$session,$data,null,['origin'=>'https://evil.example'])->status,403);
 eq(req($app,'PUT','/api/state/kord-atlas-view-v1',$session,$data)->status,200);
 eq(req($app,'PUT','/api/state/kord-atlas-view-v1',$session,$data)->status,409);
 eq(req($app,'POST','/api/state/kord-atlas-view-v1',$session,$data)->status,405);
 eq(req($app,'PUT','/api/state/kord-atlas-view-v1',$session,['version'=>'0'])->status,422);
});
test('API never accepts an owner ID supplied by the client',function(){
 [$app,$accounts]=appFixture();$a=$accounts->guest();$b=$accounts->guest();req($app,'PUT','/api/state/kord-atlas-view-v1',$a,['value'=>['query'=>'private'],'version'=>0,'userId'=>$b->userId]);
 eq(json_decode(req($app,'GET','/api/state',$b)->body,true)['state'],[]);eq(json_decode(req($app,'GET','/api/state',$a)->body,true)['state']['kord-atlas-view-v1']['value']['query'],'private');
});
test('Login/logout rotate tokens, reject stale session and never copy guest progress',function(){
 [$app,$accounts]=appFixture();$guest=$accounts->guest();$registered=req($app,'POST','/api/auth/register',$guest,['username'=>'player','password'=>'Cedar!Kite9_Moon']);eq($registered->status,200);eq($accounts->session($guest->token),null);
 preg_match('/eft_session=([a-f0-9]+)/',$registered->headers['Set-Cookie'],$m);$account=$accounts->session($m[1]);ok($account->username==='player');
 $logout=req($app,'POST','/api/auth/logout',$account);eq($logout->status,200);eq($accounts->session($account->token),null);eq(json_decode($logout->body,true)['profile']['authenticated'],false);
});
test('Five PHP pages and clean URL aliases share brand and fully wired entrypoints',function(){
 [$app]=appFixture();$logos=[];
 foreach(['index','raid','quests','quest','reference','boss','account']as $page){foreach(['/'.$page,'/'.$page.'.html']as $path){$r=req($app,'GET',$path);eq($r->status,200);ok(str_contains($r->body,'meta name="wiki-runtime" content="php"'));ok(str_contains($r->body,'id="profile-button"'));ok(str_contains($r->body,'src="bootstrap.mjs?v='.match($page){'account'=>'account-20261016b','quest','quests'=>'media-links-20261017','raid'=>'tablet-20261017b','reference'=>'mobile-btr-20261017',default=>'session-20261016'}.'"'));ok(!str_contains($r->body,'Карты рейда'));preg_match('/<a class="brand".*?<\/a>/s',$r->body,$m);$logos[]=$m[0];}}
 eq(count(array_unique($logos)),1);eq(req($app,'HEAD','/')->body,'');eq(req($app,'GET','/missing')->status,404);eq(req($app,'GET','/../../bootstrap.php')->status,404);
});
test('Static exports and PHP templates stay equivalent except runtime flag',function(){
 $renderer=new PageRenderer(dirname(__DIR__,2));foreach(['index','raid','quests','quest','reference','boss']as $page){$static=$renderer->render($page,'static');eq($static,file_get_contents(dirname(__DIR__,2).'/public/'.$page.'.html'));eq(str_replace('content="static"','content="php"',$static),$renderer->render($page));}
 try{$renderer->render('../secret');throw new LogicException('must reject');}catch(InvalidArgumentException $e){eq($e->getMessage(),'Unknown page');}
});
test('Retired price endpoints never fetch or overwrite the last snapshot',function(){
 [$db,$clock,$accounts,$states]=fixture();$directory=temporary();$snapshot='{"meta":{"date":"2026-10-11"},"items":{"saved":100}}';file_put_contents($directory.'/pve.json',$snapshot);$calls=0;
 $prices=new PriceService($directory,$clock,function()use(&$calls){$calls++;throw new RuntimeException('No upstream requests expected');});
 $app=new Application($accounts,$states,$prices,new PageRenderer(dirname(__DIR__,2)),str_repeat('s',64),'https://test.example');
 foreach(['/api/prices','/api/prices/daily']as $path)foreach(['regular','pve']as $mode){
  $r=$app->handle(new Request('GET',$path,query:['mode'=>$mode]));eq($r->status,410);eq($r->headers['Cache-Control'],'no-store');eq(json_decode($r->body,true)['code'],'prices_disabled');
 }
 eq($calls,0);eq(file_get_contents($directory.'/pve.json'),$snapshot);eq(glob($directory.'/*.lock'),[]);
});
test('Factory boot is repeatable without exposing secret material in templates',function(){
 $original=[];foreach(['APP_STORAGE','APP_URL','APP_KEY']as $name)$original[$name]=getenv($name);
 $path=temporary();putenv('APP_STORAGE='.$path);putenv('APP_URL=https://test.example');putenv('APP_KEY=');
 try{
  $a=EftWiki\Factory::services(dirname(__DIR__,2));$secret=file_get_contents($path.'/app.key');$b=EftWiki\Factory::services(dirname(__DIR__,2));eq(file_get_contents($path.'/app.key'),$secret);ok(strlen($secret)>=32);ok(!str_contains($a['app']->handle(new Request('GET','/'))->body,$secret));
 }finally{foreach($original as $name=>$value)putenv($value===false?$name:$name.'='.$value);}
});

$failed=0;$start=microtime(true);
foreach($tests as $name=>$body){try{$body();echo 'PASS '.$name."\n";}catch(Throwable $error){$failed++;echo 'FAIL '.$name.': '.$error->getMessage()."\n".$error->getTraceAsString()."\n";}}
if(function_exists('phpdbg_end_oplog')){
 $executed=phpdbg_end_oplog();$possible=phpdbg_get_executable(['opcodes'=>false]);$coverage=[];$root=realpath(dirname(__DIR__,2).'/src');
 foreach($possible as $path=>$lines){if(str_starts_with($path,$root.'/')){$hits=array_intersect_key($lines,$executed[$path]??[]);$coverage[substr($path,strlen($root)+1)]=['covered'=>count($hits),'executable'=>count($lines)];}}
 $report=['runtime'=>PHP_VERSION,'files'=>$coverage];file_put_contents(__DIR__.'/coverage.json',json_encode($report,JSON_PRETTY_PRINT));echo 'Coverage report: tests/php/coverage.json'."\n";
}
echo sprintf("%d tests, %d assertions, %d failures in %.2f s (PHP %s)\n",count($tests),$assertions,$failed,microtime(true)-$start,PHP_VERSION);
foreach($directories as $path)clean($path);
exit($failed?1:0);
