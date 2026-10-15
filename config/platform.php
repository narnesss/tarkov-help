<?php
declare(strict_types=1);

// Остальные параметры предоставляет пакет Orchid. Админка открывается на PHP origin.
return [
    'prefix' => '/admin',
    'provider' => App\Orchid\PlatformProvider::class,
    'index' => 'platform.quests',
    'profile' => 'platform.quests',
    'notifications' => ['enabled' => false],
    'fallback' => false,
    'middleware' => [
        'public' => ['web', App\Http\Middleware\PrivateAdminResponse::class],
        'private' => ['web', App\Http\Middleware\PrivateAdminResponse::class, 'platform'],
    ],
];
