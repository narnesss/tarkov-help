<?php
declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;

// API сохраняет свой cookie/HMAC CSRF-контракт; публичные страницы не открывают сессию.
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up', apiPrefix: '')
    ->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\GuardDatabaseRestore::class);
        // Guard Orchid создаётся уже в конструкторе LoginController, до route middleware.
        $middleware->prepend(\App\Http\Middleware\AdminSession::class);
        $middleware->append(\App\Http\Middleware\TrackPlayerActivity::class);
        $middleware->append(\App\Http\Middleware\DeploymentRevision::class);
        // API валидирует исходный JSON сам; не загружаем большой body ради trim/null.
        $middleware->remove([\Illuminate\Foundation\Http\Middleware\TrimStrings::class, \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['player.password']);
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response) {
            if (request()->is('api', 'api/*', 'admin', 'admin/*')) { $response->headers->set('Cache-Control', 'private, no-store'); }
            return $response;
        });
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson());
    })->create();
