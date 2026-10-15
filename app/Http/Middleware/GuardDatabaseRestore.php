<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use App\Services\DatabaseAccess;
use Closure;
use Illuminate\Http\Request;

/** Не допускает новых запросов к SQLite во время восстановления; ждём завершения старых. */
final class GuardDatabaseRestore
{
    public function handle(Request $request, Closure $next)
    {
        $database = (string) config('database.connections.sqlite.database');
        if (!$request->is('api', 'api/*', 'up') || config('database.default') !== 'sqlite' || !str_starts_with($database, '/')) {
            return $next($request);
        }
        $lock = (new DatabaseAccess($database))->acquire();
        if ($lock === null) {
            return response()->json(['error' => 'Идёт восстановление базы. Повторите запрос позже.'], 503,
                ['Cache-Control' => 'private, no-store', 'Retry-After' => '60']);
        }
        try { return $next($request); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
