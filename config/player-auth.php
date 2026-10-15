<?php
declare(strict_types=1);

return [
    'email'=>['mailer'=>env('PLAYER_EMAIL_MAILER',env('APP_ENV')==='production'?'alwaysdata':''),'from'=>env('PLAYER_EMAIL_FROM','narnes@alwaysdata.net')],
    'google' => ['client_id' => env('GOOGLE_CLIENT_ID', ''), 'client_secret' => env('GOOGLE_CLIENT_SECRET', '')],
    'discord' => ['client_id' => env('DISCORD_CLIENT_ID', ''), 'client_secret' => env('DISCORD_CLIENT_SECRET', '')],
];
