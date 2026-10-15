<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use App\Services\DatabaseAccess;
use Closure;
use Illuminate\Http\Request;

/** Закрывает SQL-запросы во время переноса или восстановления; ждём завершения старых. */
final class GuardDatabaseRestore
{
    public function handle(Request $request, Closure $next)
    {
        $database = DatabaseAccess::configuredPath();
        if (!$request->is('api', 'api/*', 'admin', 'admin/*', 'up') || !str_starts_with($database, '/')) {
            return $next($request);
        }
        $lock = (new DatabaseAccess($database))->acquire();
        if ($lock === null) {
            return response()->json(['error' => 'Идёт восстановление базы. Повторите запрос позже.'], 503,
                ['Cache-Control' => 'private, no-store', 'Retry-After' => '60']);
        }
        try {
            $response=$next($request);
            // Только оператор с существующим proxy-секретом проверяет фактический драйвер PHP после переключения.
            $token=(string)config('api.proxy_token');
            if($request->is('up')&&strlen($token)>=32&&hash_equals($token,(string)$request->header('X-Wiki-Proxy-Token'))){
                \Illuminate\Support\Facades\DB::select('SELECT 1');
                $response->headers->set('X-Wiki-Database',\Illuminate\Support\Facades\DB::getDriverName());
                $response->headers->set('Cache-Control','private, no-store');
            }
            return $response;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
