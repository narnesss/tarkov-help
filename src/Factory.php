<?php
declare(strict_types=1);
namespace EftWiki;

use EftWiki\Infrastructure\Database;
use EftWiki\Identity\AccountService;
use EftWiki\Profile\StateRepository;
use EftWiki\Prices\{PriceService, CurlTransport};
use EftWiki\Support\SystemClock;
use EftWiki\View\PageRenderer;

final class Factory
{
    public static function services(string $root): array
    {
        $var = getenv('APP_STORAGE') ?: $root . '/var';
        if (!is_dir($var)) { mkdir($var, 0700, true); }
        $keyPath = $var . '/app.key';
        $secret = getenv('APP_KEY');
        if (!$secret) {
            $file = fopen($keyPath, 'c+');
            if (!$file || !flock($file, LOCK_EX)) { throw new \RuntimeException('Application key unavailable'); }
            try {
                chmod($keyPath, 0600); $secret = trim(stream_get_contents($file));
                if ($secret === '') { $secret = bin2hex(random_bytes(32)); rewind($file); fwrite($file, $secret); fflush($file); }
            } finally { flock($file, LOCK_UN); fclose($file); }
        }
        if (strlen($secret) < 32) { throw new \RuntimeException('APP_KEY must contain at least 32 bytes'); }
        $clock = new SystemClock(); $database = new Database($var . '/wiki.sqlite');
        $accounts = new AccountService($database->pdo, $clock); $states = new StateRepository($database->pdo, $clock);
        $prices = new PriceService($var . '/prices', $clock, \Closure::fromCallable(new CurlTransport()), getenv('TARKOV_MARKET_API_KEY') ?: '');
        $origin = rtrim(getenv('APP_URL') ?: 'http://localhost:8080', '/');
        return ['app' => new Application($accounts, $states, $prices, new PageRenderer($root), $secret, $origin, str_starts_with($origin, 'https://')),
            'accounts' => $accounts, 'prices' => $prices, 'database' => $database];
    }
}
