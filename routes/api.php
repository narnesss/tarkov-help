<?php
declare(strict_types=1);

use App\Http\Controllers\ApiController;
use App\Http\Controllers\PageController;
use App\Http\Middleware\VerifyApiProxy;
use Illuminate\Support\Facades\Route;

Route::any('/api/{path?}', ApiController::class)->where('path', '.*')->middleware(VerifyApiProxy::class);
if (!config('api.only')) {
    // Штатный /up регистрируется Laravel после API-файла; не перехватываем его wildcard.
    Route::get('/{path?}', PageController::class)->where('path', '(?!up$).*')->name('site.page');
}
