<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** HTTP-проверка подтверждает версию исполняемого PHP, а не только доступность сервера. */
final class DeploymentRevision
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->is('up')) {
            $response->headers->set('Cache-Control', 'no-store');
            $revision = config('app.release');
            if (is_string($revision) && preg_match('/\A[a-f0-9]{40}-[0-9]+-[0-9]+\z/D', $revision)) {
                $response->headers->set('X-Wiki-Release', $revision);
            }
        }
        return $response;
    }
}
