<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** Общая политика cache-aside для публичного JSON; приватные данные сюда не передаются. */
final class PublicContentCache
{
    private const FORMAT = 'page-content-counts-20261017-v1';

    public function respond(Request $request, string $key, string $revision, \Closure $build): Response
    {
        $revision = hash('sha256', self::FORMAT."\0".$key."\0".$revision."\0".PublicMedia::revision());
        $headers = ['Cache-Control' => 'private, no-store', 'X-Wiki-Content-Revision' => $revision];
        // Сохраняем прежний revision=1 и новый условный запрос без построения JSON.
        if ($request->boolean('revision')) {
            return response()->json(['revision' => $revision])->withHeaders(['Cache-Control' => 'private, no-store']);
        }
        if (hash_equals($revision, (string) $request->header('X-Wiki-Content-Revision', ''))) {
            return response()->json(['revision' => $revision])->withHeaders($headers + [
                'X-Wiki-Content-Unchanged' => '1', 'X-Wiki-Content-Cache' => 'VALIDATED',
            ]);
        }
        $status = 'MISS';$json = null;
        try {
            $entry = Cache::get($key);
            if (is_array($entry) && ($entry['revision'] ?? null) === $revision && is_string($entry['gzip'] ?? null)) {
                $json = @gzdecode($entry['gzip']);
            }
        } catch (\Throwable $error) { report($error);$status = 'BYPASS'; }
        if (is_string($json)) { $status = 'HIT'; }
        else {
            // Ошибки сборки/404 не перехватываются и не попадают в кэш.
            $json = PublicMedia::json($build());
            try {
                Cache::put($key, ['revision' => $revision, 'gzip' => gzencode($json, 1)], config('content.public_cache_ttl', 86400));
            } catch (\Throwable $error) { report($error);$status = 'BYPASS'; }
        }
        return response($json, 200, $headers + [
            'Content-Type' => 'application/json; charset=utf-8', 'X-Wiki-Content-Cache' => $status,
        ]);
    }
}
