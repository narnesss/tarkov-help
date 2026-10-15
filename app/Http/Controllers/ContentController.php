<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\{ContentDataset, Quest};

/** Выдаёт публичные данные SQLite без открытия сессии игрока. */
final class ContentController
{
    public function __invoke(string $path)
    {
        abort_unless(preg_match('~^[a-z0-9][a-z0-9/._-]*\.json$~', $path) && !str_contains($path, '..'), 404);
        $dataset = ContentDataset::where('path', $path)->firstOrFail();
        if ($path === 'home.json') { return $this->home($dataset); }
        if (!preg_match('~^guide/quests-(pve|regular)\.json$~', $path, $match)) {
            return response($dataset->json(), 200, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'private, no-store']);
        }
        $data = $dataset->content();
        $tasks = []; $seen = []; $traders = collect($data['traders'] ?? [])->keyBy('id');
        // Не держим одновременно Eloquent-модели, распакованные цели и весь JSON каталога.
        // Ответ собирается до освобождения GuardDatabaseRestore, без отложенных DB-запросов.
        foreach (Quest::where('mode', $match[1])->where('published', true)->orderBy('id')->cursor() as $quest) {
            $raw = $quest->json(); $tasks[] = $raw;
            $trader = json_decode($raw, true, 128, JSON_THROW_ON_ERROR)['trader'] ?? null;
            if (is_array($trader) && isset($trader['id']) && !isset($seen[$trader['id']])) {
                $traders->put($trader['id'], [...($traders->get($trader['id']) ?? []), ...$trader]);
                $seen[$trader['id']] = true;
            }
        }
        $data['traders'] = $traders->values()->all(); $data['cms'] = true; unset($data['tasks']);
        $json = substr(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 0, -1).',"tasks":['.implode(',', $tasks).']}';
        return response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }

    /** Главная страница и поиск используют актуальные названия и только опубликованные квесты. */
    private function home(ContentDataset $dataset)
    {
        $data = $dataset->content(); $traders = [];
        foreach (['pve', 'regular'] as $mode) {
            $traders[$mode] = collect($data['modes'][$mode]['traders'] ?? [])->keyBy('id')->map(fn ($trader) => [...$trader, 'total' => 0])->all();
            $data['modes'][$mode] = ['total' => 0, 'kappa' => 0, 'story' => 0, 'tasks' => [], 'traders' => []];
        }
        foreach (Quest::where('published', true)->orderBy('id')->cursor() as $quest) {
            $task = $quest->payload; $mode = $quest->mode; $story = (bool) ($task['story'] ?? false);
            $archive = ($task['catalogSection'] ?? '') === 'supplementary';
            $trader = is_array($task['trader'] ?? null) ? $task['trader'] : ['id' => $quest->trader_id, 'name' => $quest->trader_id];
            $data['modes'][$mode]['tasks'][] = ['id' => $quest->quest_id, 'name' => $quest->name,
                'story' => $story, 'archive' => $archive, 'trader' => $trader['id']];
            if ($archive) { continue; }
            $data['modes'][$mode]['total']++;
            $data['modes'][$mode]['kappa'] += (int) ($task['kappaRequired'] ?? false);
            $data['modes'][$mode]['story'] += (int) $story;
            if (!$story && $trader['id']) {
                $previous = $traders[$mode][$trader['id']] ?? ['total' => 0];
                $traders[$mode][$trader['id']] = [...$previous, 'id' => $trader['id'], 'name' => $trader['name'] ?? $trader['id'],
                    'image' => $previous['image'] ?? $trader['imageLink'] ?? null, 'total' => $previous['total'] + 1];
            }
        }
        foreach ($traders as $mode => $records) { $data['modes'][$mode]['traders'] = array_values(array_filter($records, fn ($trader) => $trader['total'] > 0)); }
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
