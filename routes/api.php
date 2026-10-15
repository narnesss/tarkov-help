<?php
declare(strict_types=1);

use App\Http\Controllers\ApiController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\ContentQuestController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PlayerAuthController;
use App\Http\Middleware\VerifyApiProxy;
use Illuminate\Support\Facades\Route;

Route::get('/api/content/data/{path}', ContentController::class)->where('path', '.*')->middleware(VerifyApiProxy::class);
Route::get('/api/content/quest/{quest}', ContentQuestController::class)->middleware(VerifyApiProxy::class);
Route::get('/api/auth/options', [PlayerAuthController::class, 'options'])->middleware(VerifyApiProxy::class);
Route::post('/api/auth/oauth/{provider}/start', [PlayerAuthController::class, 'begin'])->where('provider','google|discord')->middleware(VerifyApiProxy::class);
Route::post('/api/auth/oauth/{provider}/finish', [PlayerAuthController::class, 'finish'])->where('provider','google|discord')->middleware(VerifyApiProxy::class);
Route::any('/api/{path?}', ApiController::class)->where('path', '(?!content(?:/|$)).*')->middleware(VerifyApiProxy::class);
if (!config('api.only')) {
    // Штатный /up регистрируется Laravel после API-файла; не перехватываем его wildcard.
    Route::get('/{path?}', PageController::class)->where('path', '(?!up$|admin(?:/|$)|api(?:/|$)).*')->name('site.page');
}
