<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Не допускает сохранения страниц админки в общем кэше. */
final class PrivateAdminResponse
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request)->header('Cache-Control', 'private, no-store');
    }
}
