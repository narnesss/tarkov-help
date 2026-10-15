<?php
declare(strict_types=1);
namespace App\Services;

use EftWiki\Identity\{AccountService, Session};
use Illuminate\Support\Facades\{DB, Http};
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Одноразовый OAuth, привязанный к opaque-сессии; токены провайдеров не сохраняются. */
final readonly class PlayerOAuth
{
    private const PROVIDERS = [
        'google' => ['authorize'=>'https://accounts.google.com/o/oauth2/v2/auth', 'token'=>'https://oauth2.googleapis.com/token', 'user'=>'https://openidconnect.googleapis.com/v1/userinfo', 'scope'=>'openid profile email'],
        'discord' => ['authorize'=>'https://discord.com/oauth2/authorize', 'token'=>'https://discord.com/api/oauth2/token', 'user'=>'https://discord.com/api/users/@me', 'scope'=>'identify email'],
    ];

    public function __construct(private AccountService $accounts, private PlayerProfiles $profiles) {}

    public function enabled(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]) && (bool)config('player-auth.'.$provider.'.client_id') && (bool)config('player-auth.'.$provider.'.client_secret');
    }

    public function options(Session $session): array
    {
        $linked=$session->username===null?[]:DB::table('player_identities')->where('user_id',$session->userId)->pluck('provider')->all();
        return array_map(fn(string $provider)=>['id'=>$provider,'enabled'=>$this->enabled($provider),'linked'=>in_array($provider,$linked,true)],array_keys(self::PROVIDERS));
    }

    private function redirectUri(string $provider): string
    {
        return rtrim((string)config('app.url'),'/').'/account?oauth='.$provider;
    }

    public function begin(string $provider, Session $session): string
    {
        abort_unless($this->enabled($provider),503,'Этот способ входа пока недоступен.');
        DB::table('player_oauth_states')->where('expires_at','<=',time())->delete();
        $state=bin2hex(random_bytes(32));
        DB::table('player_oauth_states')->insert(['token_hash'=>hash('sha256',$state),'session_hash'=>hash('sha256',$session->token),'provider'=>$provider,
            'link_user_id'=>$session->username===null?null:$session->userId,'expires_at'=>time()+600]);
        return self::PROVIDERS[$provider]['authorize'].'?'.http_build_query(['client_id'=>config('player-auth.'.$provider.'.client_id'),
            'redirect_uri'=>$this->redirectUri($provider),'response_type'=>'code','scope'=>self::PROVIDERS[$provider]['scope'],'state'=>$state]);
    }

    public function finish(string $provider, Session $session, string $state, string $code): Session
    {
        abort_unless($this->enabled($provider),503,'Этот способ входа пока недоступен.');
        abort_unless(preg_match('/^[a-f0-9]{64}$/D',$state) && strlen($code)>0 && strlen($code)<=4096,422,'Некорректный ответ сервиса входа.');
        $query=DB::table('player_oauth_states')->where('token_hash',hash('sha256',$state))->where('session_hash',hash('sha256',$session->token))
            ->where('provider',$provider)->where('expires_at','>',time());
        $challenge=(clone $query)->first();
        abort_unless($challenge && $query->delete()===1,403,'Ссылка входа истекла. Начните вход ещё раз.');
        try {
            $token=Http::asForm()->connectTimeout(2)->timeout(3)->post(self::PROVIDERS[$provider]['token'],[
                'client_id'=>config('player-auth.'.$provider.'.client_id'),'client_secret'=>config('player-auth.'.$provider.'.client_secret'),
                'code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>$this->redirectUri($provider),
            ])->throw()->json('access_token');
            if(!is_string($token)||$token===''){throw new \RuntimeException('Missing token');}
            $user=Http::withToken($token)->connectTimeout(2)->timeout(3)->get(self::PROVIDERS[$provider]['user'])->throw()->json();
            $subject=$user[$provider==='google'?'sub':'id']??null;
            if(!is_string($subject)||!preg_match('/^[a-zA-Z0-9_-]{1,255}$/D',$subject)){throw new \RuntimeException('Missing subject');}
            $display=PlayerProfiles::providerName((string)($user['name']??$user['global_name']??$user['username']??ucfirst($provider)));
            $avatar=PlayerProfiles::avatar($provider,$user);
            $email=$this->verifiedEmail($provider,$user);
            // Google не подтверждает текущее владение внешней почтой без Workspace.
            $autoLink=$email!==null && ($provider==='discord'||str_ends_with($email,'@gmail.com')||str_ends_with($email,'@googlemail.com')
                || (is_string($user['hd']??null)&&$user['hd']!==''&&str_ends_with($email,'@'.strtolower($user['hd']))));
        } catch(\Throwable) { throw new HttpException(502,'Не удалось подтвердить вход. Попробуйте ещё раз.'); }
        try {
            return DB::transaction(function() use($provider,$subject,$display,$avatar,$email,$autoLink,$session,$challenge): Session {
                // Сериализует также случай, когда совпадающей почты ещё нет: два первых входа не создадут дубликат.
                DB::table('player_auth_locks')->where('id',1)->lockForUpdate()->first();
                $identity=DB::table('player_identities')->where('provider',$provider)->where('subject',$subject)->lockForUpdate()->first();
                if($challenge->link_user_id!==null){
                    abort_unless($challenge->link_user_id===$session->userId && $session->username!==null,403,'Сессия изменилась. Повторите вход.');
                    abort_if($identity && $identity->user_id!==$session->userId,409,'Этот способ входа уже связан с другим аккаунтом.');
                    $owner=$session->userId;
                } elseif($identity){$owner=$identity->user_id;}
                else {
                    $matches=$email===null?collect():DB::table('player_identities')->where('verified_email',$email)->get();
                    $owners=$matches->pluck('user_id')->unique()->values();
                    abort_if($owners->count()>1 || ($owners->count()===1 && (!$autoLink||!$matches->contains(fn($match)=>(bool)$match->email_auto_link))),409,
                        'Чтобы подключить этот способ входа, войдите в существующий аккаунт и подключите его в личном кабинете.');
                    $owner=$owners->first();
                    if($owner===null){
                        $owner=bin2hex(random_bytes(16));
                        DB::table('users')->insert(['id'=>$owner,'username'=>$provider.'_'.bin2hex(random_bytes(6)),'password_hash'=>null,'created_at'=>time()]);
                    }
                }
                abort_if(!$identity && DB::table('player_identities')->where('user_id',$owner)->where('provider',$provider)->exists(),409,
                    'У аккаунта уже подключён другой профиль этого сервиса. Войдите прежним способом.');
                $values=['display_name'=>$display??ucfirst($provider),'verified_email'=>$email,'email_auto_link'=>$autoLink];
                if(!$identity){DB::table('player_identities')->insert([...$values,'provider'=>$provider,'subject'=>$subject,'user_id'=>$owner,'created_at'=>time()]);}
                else {DB::table('player_identities')->where('provider',$provider)->where('subject',$subject)->update($values);}
                $this->profiles->refresh($owner,$display,$avatar);
                $next=$this->accounts->issueFor($owner); $this->accounts->revoke($session);
                return $next;
            });
        } catch(\Illuminate\Database\QueryException) { throw new HttpException(409,'Способ входа уже подключён. Начните вход ещё раз.'); }
    }

    private function verifiedEmail(string $provider,array $user): ?string
    {
        if(($user[$provider==='google'?'email_verified':'verified']??false)!==true){return null;}
        $email=$user['email']??null;
        if(!is_string($email)){return null;}
        $email=strtolower(trim($email));
        return strlen($email)<=254 && filter_var($email,FILTER_VALIDATE_EMAIL)?$email:null;
    }
}
