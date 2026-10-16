<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Services\{PlayerOAuth, PlayerProfiles, PlayerEmails, PlayerPasswordRecovery};
use EftWiki\Identity\{AccountService, Session};
use Illuminate\Http\{JsonResponse, Request};

/** Внешние способы входа сохраняют cookie/HMAC CSRF-контракт публичного API. */
final readonly class PlayerAuthController
{
    public function __construct(private AccountService $accounts, private PlayerOAuth $oauth, private PlayerProfiles $profiles, private PlayerEmails $emails, private PlayerPasswordRecovery $recovery) {}

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
        $session=$this->session($request);
        return response()->json(['providers'=>$this->oauth->options($session),'legal'=>app(\App\Services\LegalConsents::class)->publicData($session->username!==null?$session->userId:null)])->header('Cache-Control','private, no-store');
    }

    public function begin(Request $request,string $provider): JsonResponse
    {
        $session=$this->session($request,true);
        $this->accounts->rateLimit((string)($request->attributes->get('wiki_client_ip')??$request->ip()),'login');
        return response()->json(['url'=>$this->oauth->begin($provider,$session,$request->all())])->header('Cache-Control','private, no-store');
    }

    public function profile(Request $request): JsonResponse
    {
        $session=$this->session($request,true);
        $data=$request->validate(['display_name'=>'required|string|max:64']);
        $this->profiles->rename($session,$data['display_name']);
        return response()->json($this->profiles->publicData($session))->header('Cache-Control','private, no-store');
    }

    public function emailStatus(Request $request): JsonResponse
    { return response()->json($this->emails->status($this->session($request)))->header('Cache-Control','private, no-store'); }

    public function sendEmail(Request $request): JsonResponse
    {
        $session=$this->session($request,true);
        $data=$request->validate(['email'=>'required|string|max:254']);
        return response()->json($this->emails->send($session,$data['email'],(string)($request->attributes->get('wiki_client_ip')??$request->ip())))
            ->header('Cache-Control','private, no-store');
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $session=$this->session($request,true);
        $data=$request->validate(['challenge_id'=>'required|string|regex:/^[a-f0-9]{32}$/D','code'=>'required|string|regex:/^[0-9]{8}$/D']);
        return response()->json($this->emails->verify($session,$data['challenge_id'],$data['code']))->header('Cache-Control','private, no-store');
    }

    public function requestPassword(Request $request): JsonResponse
    {
        $session=$this->session($request,true);
        $data=$request->validate(['email'=>'required|string|max:254']);
        return response()->json($this->recovery->request($session,$data['email'],(string)($request->attributes->get('wiki_client_ip')??$request->ip())))
            ->header('Cache-Control','private, no-store');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $session=$this->session($request,true);
        $data=$request->validate(['challenge_id'=>'required|string|regex:/^[a-f0-9]{32}$/D','code'=>'required|string|regex:/^[0-9]{8}$/D',
            'password'=>'required|string|confirmed','password_confirmation'=>'required|string']);
        return response()->json($this->recovery->reset($session,$data['challenge_id'],$data['code'],$data['password']))->header('Cache-Control','private, no-store');
    }

    public function finish(Request $request,string $provider): JsonResponse
    {
        $session=$this->session($request,true);
        $data=$request->validate(['state'=>'required|string|size:64','code'=>'required|string|max:4096']);
        $next=$this->oauth->finish($provider,$session,$data['state'],$data['code']);
        return response()->json($this->profiles->publicData($next))->header('Cache-Control','private, no-store')
            ->header('Set-Cookie','eft_session='.$next->token.'; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax'.(str_starts_with((string)config('app.url'),'https://')?'; Secure':''));
    }
}
