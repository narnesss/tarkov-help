<?php
declare(strict_types=1);

return [
    'google' => ['client_id' => env('GOOGLE_CLIENT_ID', ''), 'client_secret' => env('GOOGLE_CLIENT_SECRET', '')],
    'discord' => ['client_id' => env('DISCORD_CLIENT_ID', ''), 'client_secret' => env('DISCORD_CLIENT_SECRET', '')],
];
