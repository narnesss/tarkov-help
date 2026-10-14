<?php
declare(strict_types=1);
namespace EftWiki\Prices;

final class CurlTransport
{
    public function __invoke(string $url, array $headers): array
    {
        $handle = curl_init($url); $body = '';
        curl_setopt_array($handle, [CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 18, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 40000000) { return 0; }
                $body .= $chunk; return strlen($chunk);
            }]);
        $success = curl_exec($handle); $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle);
        if ($success === false || $status !== 200) { throw new \RuntimeException('Price upstream unavailable'); }
        return json_decode($body, true, 128, JSON_THROW_ON_ERROR);
    }
}
