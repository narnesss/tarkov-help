<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\Quest;

/** Проверяет существование нового квеста для маршрута статической оболочки Worker. */
final class ContentQuestController
{
    public function __invoke(string $quest, \Illuminate\Http\Request $request)
    {
        return response()->json(['exists' => Quest::where($request->boolean('trader') ? 'trader_id' : 'quest_id', $quest)->where('published', true)->exists()])
            ->header('Cache-Control', 'private, no-store');
    }
}
