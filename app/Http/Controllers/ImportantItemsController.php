<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Services\{AdminInput, PageViews};
use Illuminate\Http\Request;

/** Обычная GET-пагинация; Worker получает готовую страницу без cookies и прогресса. */
final class ImportantItemsController
{
    public function __invoke(Request $request, PageViews $views)
    {
        $request->merge(['q' => AdminInput::trim($request->query('q', ''))]);
        $params = $request->validate(['q' => ['string', 'max:160'], 'mode' => ['sometimes', 'string', 'in:pve,regular'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'kind' => ['sometimes', 'string', 'in:all,quest,hideout'], 'fir' => ['sometimes', 'string', 'in:all,fir']]);
        $params['q'] = AdminInput::search($params['q'], 'q');
        $html = $views->render('important-items', 'php', null, $params);
        if ($request->is('api/*')) { return response()->json(['html' => $html])->header('Cache-Control', 'private, no-store'); }
        return response($html)->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'public, max-age=0, must-revalidate');
    }
}
