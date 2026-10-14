<?php
declare(strict_types=1);
namespace EftWiki\Prices;

use EftWiki\Support\Clock;

final readonly class PriceService
{
    public function __construct(private string $directory, private Clock $clock, private \Closure $fetch, private string $apiKey = '')
    { if (!is_dir($directory)) { mkdir($directory, 0700, true); } }

    public static function day(int $time): string { return gmdate('Y-m-d', $time + 10800); }

    public static function normalize(array $raw, string $provider): array
    {
        $rows = $provider === 'tarkov-market' ? $raw : ($raw['data']['items'] ?? []); $items = [];
        $number = static fn(mixed $v): int|float|null => is_numeric($v) && is_finite((float) $v) && $v > 0 ? 0 + $v : null;
        foreach ($rows as $row) {
            $id = $row[$provider === 'tarkov-market' ? 'bsgId' : 'id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D', $id)) { continue; }
            if ($provider === 'tarkov-market') {
                $usable = ($row['haveMarketData'] ?? true) !== false && !($row['bannedOnFlea'] ?? false);
                $items[$id] = ['lastLowPrice' => $usable ? $number($row['price'] ?? null) : null,
                    'avg24hPrice' => $usable ? $number($row['avg24hPrice'] ?? null) : null,
                    'updated' => $row['updated'] ?? null, 'bannedOnFlea' => (bool) ($row['bannedOnFlea'] ?? false),
                    'marketTraderName' => $row['traderName'] ?? null, 'marketTraderPrice' => $number($row['traderPriceRub'] ?? null), 'marketTraderCurrency' => 'RUB'];
            } else {
                $items[$id] = ['lastLowPrice' => $number($row['lastLowPrice'] ?? null), 'avg24hPrice' => $number($row['avg24hPrice'] ?? null),
                    'updated' => $row['updated'] ?? null, 'buyFromTrader' => $row['buyFromTrader'] ?? [], 'sellToTrader' => $row['sellToTrader'] ?? []];
            }
        }
        if (count($items) < 100) { throw new \RuntimeException('Incomplete price snapshot'); }
        return $items;
    }

    private function read(string $path): ?array
    {
        if (!is_file($path)) { return null; }
        try { $data = json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR); return is_array($data) ? $data : null; }
        catch (\JsonException) { return null; }
    }

    private function atomicWrite(string $path, array $value): void
    {
        $temporary = tempnam($this->directory, 'price-');
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($temporary, $encoded) !== strlen($encoded)) { throw new \RuntimeException('Incomplete cache write'); }
            chmod($temporary, 0600);
            if (!rename($temporary, $path)) { throw new \RuntimeException('Cache write failed'); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }

    public function snapshot(string $mode): array
    {
        if (!in_array($mode, ['regular', 'pve'], true)) { throw new \InvalidArgumentException('invalid_mode'); }
        $lock = fopen($this->directory . '/' . $mode . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new \RuntimeException('Cache lock unavailable'); }
        try {
            $path = $this->directory . '/' . $mode . '.json'; $previous = $this->read($path);
            $provider = $this->apiKey !== '' ? 'tarkov-market' : 'tarkov.dev'; $now = $this->clock->now();
            if (($previous['meta']['day'] ?? null) === self::day($now) && ($previous['meta']['provider'] ?? null) === $provider) { return [...$previous, 'stale' => false]; }
            $failurePath = $this->directory . '/' . $mode . '-failure.json'; $failure = $this->read($failurePath);
            try {
                if (($failure['provider'] ?? null) === $provider && ($failure['retryAfter'] ?? 0) > $now) { throw new \RuntimeException('retry_later'); }
                $url = $provider === 'tarkov-market' ? 'https://api.tarkov-market.app/api/v1/' . ($mode === 'pve' ? 'pve/' : '') . 'items/all?lang=ru' : "https://json.tarkov.dev/$mode/items";
                $headers = ['Accept: application/json']; if ($this->apiKey !== '') { $headers[] = 'x-api-key: ' . $this->apiKey; }
                $items = self::normalize(($this->fetch)($url, $headers), $provider);
                $fresh = ['schemaVersion' => 1, 'items' => $items, 'meta' => ['provider' => $provider, 'mode' => $mode,
                    'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'day' => self::day($now), 'count' => count($items),
                    'sourceUpdatedAt' => null, 'sourceUrl' => $provider === 'tarkov-market' ? 'https://tarkov-market.ru/dev/api' : 'https://tarkov.dev/api/',
                    'marketConnected' => $provider === 'tarkov-market', 'timeZone' => 'Europe/Moscow']];
                $this->atomicWrite($path, $fresh); return [...$fresh, 'stale' => false];
            } catch (\Throwable $error) {
                if (($failure['retryAfter'] ?? 0) <= $now || ($failure['provider'] ?? null) !== $provider) {
                    try { $this->atomicWrite($failurePath, ['provider' => $provider, 'retryAfter' => $now + 1800]); }
                    catch (\Throwable) { /* A failed retry journal must not hide the last successful snapshot. */ }
                }
                if ($previous) { return [...$previous, 'stale' => true, 'warning' => 'Не удалось обновить источник. Показаны сохранённые цены.']; }
                throw $error;
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function daily(): array
    {
        $results = [];
        foreach (['regular', 'pve'] as $mode) {
            try { $data = $this->snapshot($mode); $results[] = [...$data['meta'], 'stale' => $data['stale'], 'ok' => !$data['stale']]; }
            catch (\Throwable) { $results[] = ['mode' => $mode, 'ok' => false, 'message' => 'Источник временно недоступен. Сохранённые данные не удалены.']; }
        }
        return ['ok' => !in_array(false, array_column($results, 'ok'), true), 'results' => $results];
    }
}
