<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Админка использует собственную DB-сессию, которую можно отозвать при restore. */
final class AdminSession
{
    public function handle(Request $request, Closure $next)
    {
        config(['session.driver' => 'database', 'session.table' => 'admin_sessions',
            'session.cookie' => 'wiki_admin_session', 'session.path' => '/admin',
            'session.secure' => $request->isSecure()]);
        return $next($request);
    }
}
