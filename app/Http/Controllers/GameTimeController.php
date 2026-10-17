<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use EftWiki\Support\Clock;
use Illuminate\Http\JsonResponse;

/** Серверная опора двух игровых часов без обращения к аккаунту или БД. */
final class GameTimeController
{
    public function __invoke(Clock $clock): JsonResponse
    {
        return response()->json(['unixMilliseconds' => $clock->now() * 1000, 'scale' => 7, 'offsetMilliseconds' => 10800000])
            ->header('Cache-Control', 'private, no-store');
    }
}
