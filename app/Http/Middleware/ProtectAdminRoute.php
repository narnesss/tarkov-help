<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Orchid переносит query в route parameters: выбранную запись определяет только маршрут. */
final class ProtectAdminRoute
{
    public function handle(Request $request,Closure $next)
    {
        foreach(['type','record','quest','dataset','player','method'] as $field){
            if($request->query->has($field)){throw ValidationException::withMessages([$field=>'Параметр записи нельзя переопределять в строке запроса.']);}
        }
        return $next($request);
    }
}
