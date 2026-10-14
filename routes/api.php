<?php
declare(strict_types=1);

use App\Http\Controllers\ApiController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::any('/api/{path?}', ApiController::class)->where('path', '.*');
Route::get('/{path?}', PageController::class)->where('path', '.*')->name('site.page');
