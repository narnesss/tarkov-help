<?php
declare(strict_types=1);
namespace App\Services;

/** Предзагружает код выбранной страницы без выполнения и без чтения личных данных. */
final class PageModules
{
    public function preloads(string $page): array
    {
        $entry = config('pages.'.$page.'.module');
        if (!is_string($entry)) { return []; }
        $pending = ['bootstrap.mjs?v=432953184e3a4e0f', $entry];
        $urls = [];
        while ($pending !== []) {
            $url = array_shift($pending);
            if (isset($urls[$url])) { continue; }
            if (!preg_match('~^(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_.-]+\.mjs(?:\?v=[a-zA-Z0-9_-]+)?$~D', $url)) {
                throw new \LogicException('Недопустимый путь публичного модуля.');
            }
            $file = strtok($url, '?');
            $source = file_get_contents(public_path($file));
            $urls[$url] = true;
            // Только буквальные статические import/from. Динамические разделы не загружаются.
            preg_match_all('~(?:^|[;\r\n])\s*(?:import\s+(?:[^;\x27"]*?\s+from\s*)?|export\s+[^;\x27"]*?\s+from\s*)[\x27"](\./[^\x27"]+\.mjs(?:\?v=[a-zA-Z0-9_-]+)?)[\x27"]~', $source, $matches);
            foreach ($matches[1] as $dependency) {
                $directory = dirname($file);
                $pending[] = ($directory === '.' ? '' : $directory.'/').substr($dependency, 2);
            }
        }
        return array_keys($urls);
    }
}
