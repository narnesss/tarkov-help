<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Доверяет адресу клиента только после проверки секрета Cloudflare Worker. */
final class VerifyApiProxy
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('api.proxy_token');
        $provided = (string) $request->header('X-Wiki-Proxy-Token', '');
        $trusted = strlen($secret) >= 32 && hash_equals($secret, $provided);
        if (config('api.proxy_required') && !$trusted) {
            return response()->json(['error' => 'API доступен через основной сайт.'], strlen($secret) >= 32 ? 403 : 503)
                ->header('Cache-Control', 'private, no-store');
        }
        if ($trusted) {
            $ip = (string) $request->header('X-Wiki-Client-IP', '');
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return response()->json(['error' => 'Некорректный адрес клиента.'], 400)->header('Cache-Control', 'private, no-store');
            }
            $request->attributes->set('wiki_client_ip', $ip);
        }
        return $next($request);
    }
}
