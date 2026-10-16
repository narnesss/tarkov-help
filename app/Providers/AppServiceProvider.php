<?php
declare(strict_types=1);
namespace App\Providers;

use EftWiki\Application;
use EftWiki\Identity\AccountService;
use EftWiki\Profile\StateRepository;
use EftWiki\Prices\{PriceService, CurlTransport};
use EftWiki\Support\{Clock, SystemClock};
use EftWiki\View\PageRenderer;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;

/** Связывает доменные сервисы с Laravel DB/DI; ключ не создаётся во время запроса. */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\WikiSchema::class);
        $this->app->singleton(\App\Services\WikiSync::class);
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(AccountService::class, fn ($app) => new AccountService(DB::connection()->getPdo(), $app->make(Clock::class)));
        $this->app->singleton(StateRepository::class, fn ($app) => new StateRepository(DB::connection()->getPdo(), $app->make(Clock::class)));
        $this->app->singleton(PageRenderer::class, fn () => new PageRenderer(base_path()));
        $this->app->singleton(PriceService::class, fn ($app) => new PriceService(storage_path('app/prices'), $app->make(Clock::class), \Closure::fromCallable(new CurlTransport())));
        $this->app->singleton(Application::class, function ($app): Application {
            $secret = (string) config('app.key');
            if (strlen($secret) < 32) { throw new \RuntimeException('APP_KEY required; run artisan key:generate'); }
            $origin = rtrim((string) config('app.url'), '/');
            return new Application($app->make(AccountService::class), $app->make(StateRepository::class), $app->make(PriceService::class),
                $app->make(PageRenderer::class), $secret, $origin, str_starts_with($origin, 'https://'),function(array $input,\Closure $create) use($app) {
                    $consents=$app->make(\App\Services\LegalConsents::class);
                    try{$version=$consents->accept($input);}
                    catch(\Illuminate\Validation\ValidationException $error){throw new \EftWiki\Http\HttpError(422,'Подтвердите согласия перед регистрацией.',['errors'=>$error->errors()]);}
                    return DB::transaction(function() use($consents,$version,$create) {
                        $session=$create();$consents->record($session->userId,$version,'password');return $session;
                    });
                });
        });
    }
}
