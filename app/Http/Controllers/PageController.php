<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Services\{PageCatalog, PageViews};
use Illuminate\Http\{Request, Response};

/** Общие PHP-страницы обслуживаются без cookie-сессии и приватного состояния. */
final readonly class PageController
{
    public function __construct(private PageCatalog $catalog, private PageViews $views) {}

    /** Возвращает известную страницу с ETag либо настоящий HTTP 404. */
    public function __invoke(Request $request): Response
    {
        $entry = $this->catalog->resolve('/'.$request->path());
        if (!$entry && preg_match('~^quests/(trader/)?([a-zA-Z0-9_-]+)$~', trim($request->path(), '/'), $match)
            && \Illuminate\Support\Facades\Schema::hasTable('quests')) {
            $trader = !empty($match[1]);
            $quest = \App\Models\Quest::where($trader ? 'trader_id' : 'quest_id', $match[2])->where('published', true)->first();
            if ($quest) { $entry = ['template' => $trader ? 'quests' : 'quest', 'title' => $trader ? 'Квесты торговца' : $quest->name,
                'params' => [$trader ? 'trader' : 'quest' => $match[2]]]; }
        }
        if (!$entry) {
            return response($this->views->render('not-found'), 404, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
        }
        $html = $this->views->render($entry['template'], 'php', $entry['title'], $entry['params']);
        $response = response($html, 200, ['Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=0, must-revalidate', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN', 'Referrer-Policy' => 'strict-origin-when-cross-origin']);
        $response->setEtag(hash('sha256', $html));
        $response->isNotModified($request);
        return $response;
    }
}
