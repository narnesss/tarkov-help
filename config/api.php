<?php
declare(strict_types=1);

return [
    // На alwaysdata API доступен только через доверенный Worker.
    'proxy_required' => (bool) env('API_PROXY_REQUIRED', false),
    'proxy_token' => env('API_PROXY_TOKEN', ''),
    'only' => (bool) env('API_ONLY', false),
];
