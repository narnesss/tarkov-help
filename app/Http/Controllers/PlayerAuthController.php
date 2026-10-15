<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Services\PlayerOAuth;
use EftWiki\Identity\{AccountService, Session};
use Illuminate\Http\{JsonResponse, Request};

/** Внешние способы входа сохраняют cookie/HMAC CSRF-контракт публичного API. */
final readonly class PlayerAuthController
{
    public function __construct(private AccountService $accounts, private PlayerOAuth $oauth) {}

    private function session(Request $request, bool $write=false): Session
    {
        $session=$this->accounts->session($request->cookie('eft_session'));
        abort_unless($session,401,'Сессия истекла. Обновите страницу.');
        if($write){
            abort_unless($request->header('Origin')===rtrim((string)config('app.url'),'/') && hash_equals($session->csrf((string)config('app.key')),(string)$request->header('X-CSRF-Token')),403,'Запрос не прошёл проверку сессии.');
        }
        return $session;
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['providers'=>$this->oauth->options($this->session($request))])->header('Cache-Control','private, no-store');
    }

    public function begin(Request $request,string $provider): JsonResponse
    {
        $session=$this->session($request,true);
        $this->accounts->rateLimit((string)($request->attributes->get('wiki_client_ip')??$request->ip()),'login');
        return response()->json(['url'=>$this->oauth->begin($provider,$session)])->header('Cache-Control','private, no-store');
    }

    public function finish(Request $request,string $provider): JsonResponse
    {
        $session=$this->session($request,true);
        $data=$request->validate(['state'=>'required|string|size:64','code'=>'required|string|max:4096']);
        $next=$this->oauth->finish($provider,$session,$data['state'],$data['code']);
        return response()->json($next->publicData((string)config('app.key')))->header('Cache-Control','private, no-store')
            ->header('Set-Cookie','eft_session='.$next->token.'; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax'.(str_starts_with((string)config('app.url'),'https://')?'; Secure':''));
    }
}
