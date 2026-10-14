<?php
declare(strict_types=1);
namespace EftWiki\View;

use App\Services\{PageCatalog, PageViews};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/** Совместимость старых доменных тестов; разметка и каталог предоставляются Laravel. */
final readonly class PageRenderer
{
    private static function application(string $root): Application
    {
        $app = Application::getInstance();
        if (!$app || !$app->isBooted()) {
            $app = require $root.'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
        }
        return $app;
    }

    public function __construct(private string $root) {}
    public function pageForPath(string $path): ?string
    { return self::application($this->root)->make(PageCatalog::class)->resolve($path)['template'] ?? null; }
    public function render(string $page, string $runtime = 'php'): string
    { return self::application($this->root)->make(PageViews::class)->render($page, $runtime); }
}
