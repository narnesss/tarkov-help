<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Services\{PageCatalog, PageViews, QuestSeo, EntitySeo, SiteMap};
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Публичный HTML через защищённый same-origin proxy, без сессии игрока. */
final class SeoController
{
    public function __invoke(Request $request, PageCatalog $catalog, PageViews $views, QuestSeo $quests, EntitySeo $entities, SiteMap $sitemap)
    {
        $input = $request->validate([
            'path' => ['bail', 'required', 'string', 'max:256', 'regex:~^/(?:sitemap\.xml|sitemaps/(?:quests|pages|items-pve|items-regular)\.xml|(?:quests?|reference|boss|raid|documents)(?:/[a-zA-Z0-9_-]+){0,2}(?:\.html)?/?)$~D'],
            'mode' => ['bail', 'sometimes', 'string', 'in:pve,regular'],
            'page' => ['bail', 'sometimes', 'integer', 'min:1', 'max:10000'],
            'quest' => ['bail', 'sometimes', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'trader' => ['bail', 'sometimes', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'section' => ['bail', 'sometimes', 'string', 'in:all,story,archive'],
            'item' => ['bail', 'sometimes', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'boss' => ['bail', 'sometimes', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'map' => ['bail', 'sometimes', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'tab' => ['bail', 'sometimes', 'string', 'in:items,ammo,needed,barters,crafts,hideout,bosses,traders,achievements,btr'],
        ]);
        $path = $input['path'];
        if (str_ends_with($path, '.xml')) {
            return $this->respond(['seoVersion' => 1, 'status' => 200, 'xml' => $sitemap->xml($path)]);
        }
        $entry = $catalog->resolve($path);
        if (!$entry && preg_match('~^/quests/(trader/)?([a-zA-Z0-9_-]+)/?$~D', $path, $match)) {
            $entry = ['template' => empty($match[1]) ? 'quest' : 'quests', 'title' => null, 'params' => [empty($match[1]) ? 'quest' : 'trader' => $match[2]]];
        }
        if (!$entry && preg_match('~^/(reference/items|boss|raid|documents)/([a-zA-Z0-9_-]+)/?$~D', $path, $match)) {
            $page = $match[1] === 'reference/items' ? 'reference' : $match[1];
            $key = match ($page) { 'reference'=>'item', 'boss'=>'boss', default=>'map' };
            $entry = ['template'=>$page, 'title'=>null, 'params'=>[$key=>$match[2]]];
        }
        $status = 200;
        try {
            abort_unless($entry && in_array($entry['template'], ['quest', 'quests', 'reference', 'boss', 'raid', 'documents'], true), 404);
            $page = $entry['template'];
            $keys = match ($page) {
                'quest', 'quests'=>['mode','page','quest','trader','section'],
                'reference'=>['mode','page','item','tab'],
                'boss'=>['mode','page','boss'],
                default=>['mode','page','map'],
            };
            $params = array_merge(array_intersect_key($input, array_flip($keys)), $entry['params']);
            if ($entry['template'] === 'quest' && empty($params['quest'])) { abort(404); }
            if (in_array($page, ['quest', 'quests'], true)) {
                $content = $quests->content($params);
                $page = isset($params['quest']) ? 'quest' : 'quests';
            } else { $content = $entities->content($page, $params); }
            $params = array_replace($params, $content['canonicalParams'] ?? []);
            $html = $views->render($page, 'php', $content['title'] ?? $content['task']['name'] ?? $entry['title'], $params, $content);
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 404) { throw $error; }
            $status = 404; $html = $views->render('not-found');
        }
        return $this->respond(['seoVersion' => 1, 'status' => $status, 'html' => $html], $status);
    }

    private function respond(array $body, int $status = 200)
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        abort_if(strlen($json) > 2 * 1024 * 1024, 503, 'Страница временно недоступна.');
        return response($json, $status, ['Content-Type'=>'application/json; charset=utf-8', 'Cache-Control'=>'private, no-store', 'X-Robots-Tag'=>'noindex, nofollow']);
    }
}
