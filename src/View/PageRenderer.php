<?php
declare(strict_types=1);
namespace EftWiki\View;

final readonly class PageRenderer
{
    public function __construct(private string $root) {}
    public function pageForPath(string $path): ?string
    {
        $path = rawurldecode($path);
        if (str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '\\')) { return null; }
        $plain = preg_replace('/\.(?:html|php)$/', '', $path);
        $direct = ['/' => 'index', '/index' => 'index', '/documents' => 'documents', '/raid' => 'raid',
            '/quests' => 'quests', '/quest' => 'quest', '/reference' => 'reference', '/boss' => 'boss', '/quest-map-source' => 'quest-map-source'];
        if (isset($direct[$plain])) { return $direct[$plain]; }
        if (!is_file($this->root . '/public' . $plain . '.html')) { return null; }
        if (str_starts_with($plain, '/quests/trader/') || in_array($plain, ['/quests/all', '/quests/story', '/quests/archive'], true)) { return 'quests'; }
        if (str_starts_with($plain, '/quests/')) { return 'quest'; }
        foreach (['raid' => 'raid', 'documents' => 'documents', 'reference' => 'reference', 'boss' => 'boss', 'quest-map' => 'quest-map-source'] as $prefix => $page) {
            if (str_starts_with($plain, '/' . $prefix . '/')) { return $page; }
        }
        return null;
    }
    public function render(string $page, string $runtime = 'php'): string
    {
        $pages = require $this->root . '/config/pages.php';
        if (!isset($pages[$page])) { throw new \InvalidArgumentException('Unknown page'); }
        $meta = $pages[$page]; $root = $this->root;
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        try { require $root . '/templates/layout.php'; return ob_get_contents(); }
        finally { ob_end_clean(); }
    }
}
