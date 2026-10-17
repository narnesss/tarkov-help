<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Отмечает успешные обращения аккаунта к личному API не чаще раза в минуту. */
final class TrackPlayerActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response->getStatusCode() >= 400 || !$request->is('api/session', 'api/state', 'api/state/*', 'api/auth/*')) {
            return $response;
        }
        $token = $request->cookie('eft_session');
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'eft_session') {$token = $cookie->getValue();}
        }
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {return $response;}
        $now = time();
        $owner = DB::table('sessions')->join('users', 'users.id', '=', 'sessions.user_id')
            ->where('token_hash', hash('sha256', $token))->where('expires_at', '>', $now)
            ->whereNotNull('username')->value('users.id');
        if ($owner !== null) {
            DB::table('player_activity')->insertOrIgnore(['user_id'=>$owner, 'last_active_at'=>$now]);
            DB::table('player_activity')->where('user_id', $owner)->where('last_active_at', '<=', $now-60)
                ->update(['last_active_at'=>$now]);
        }
        return $response;
    }
}
