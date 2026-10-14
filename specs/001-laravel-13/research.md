# Исследование

Laravel 13 требует PHP >= 8.3; production/CI использует PHP 8.4.
https://laravel.com/docs/13.x/releases
Workers обслуживает dist как Static Assets и не является PHP-хостингом.
https://developers.cloudflare.com/workers/static-assets/
Существующий API использует opaque cookie, HMAC CSRF, hashed sessions, SQLite CAS.
Сохраняем этот проверенный доменный контракт; Laravel управляет HTTP, DI, миграциями,
Blade, Cache и Artisan. Дублировать аккаунты или менять формат прогресса не требуется.
