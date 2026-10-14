# Escape from Tarkov Wiki — Laravel 13

Источник сайта — PHP/Laravel 13 и Blade. Карты, фильтры, галереи и личный прогресс
в браузере продолжают использовать существующие JavaScript-модули.

## Структура

- app, bootstrap, routes, config — Laravel HTTP, DI, Cache и Artisan.
- resources/views — Blade/PHP, общий layout и страницы.
- src — проверенные доменные сервисы аккаунтов, CSRF и сохранения прогресса.
- database/migrations — совместимая SQLite-схема; composer.lock фиксирует зависимости.
- public — web root, исходные браузерные модули/ассеты и экспорт оболочек.
- dist — сгенерированная статическая публикация Cloudflare.
- specs/cloudflare-deployment.md — обязательные правила публикации для будущих изменений.

## PHP-хостинг

Laravel 13 поддерживает PHP >= 8.3; production/CI — PHP 8.4 с PDO SQLite, mbstring,
curl, XML и стандартными расширениями Laravel. Корень веб-сервера — public.

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
php artisan serve
```

Существующая БД: DB_DATABASE=/absolute/path/var/wiki.sqlite перед migrate.
Сделайте резервную копию; миграция принимает существующие таблицы и не удаляет записи.
APP_STORAGE совместим с прежним размещением БД. APP_KEY обязателен; храните его
и .env вне Git, используйте APP_URL с настоящим HTTPS origin.

Production: APP_DEBUG=false, composer install --no-dev --optimize-autoloader,
php artisan migrate --force, php artisan optimize. При обновлении сначала
php artisan optimize:clear, затем optimize. Нужны права записи только в storage
и bootstrap/cache. Подробности: specs/001-laravel-13/quickstart.md.

## Кэширование

Laravel Cache по умолчанию файловый. SITE_CACHE_TTL=600 задаёт TTL публичного
каталога и Blade-оболочек. Ключ включает содержимое данных/шаблонов и параметры
страницы, поэтому новые исходники не используют старый HTML. Redis можно включить
через CACHE_STORE=redis при наличии соответствующего сервера/драйвера.
Публичные PHP-страницы используют ETag и условные GET/HEAD; HTTP-кэш перепроверяет
свежесть. Общий кэш не содержит cookie, пользователя, CSRF или личного прогресса.
Ответы API — private, no-store. Cloudflare кэширует статические assets; _headers
задаёт TTL 1 час для CSS/JS, сутки для изображений и 5 минут для общих JSON-данных.

## Cloudflare Workers

Workers публикует статический dist; он не запускает PHP или Laravel API.
На Cloudflare прогресс хранится в браузере; серверные аккаунты и синхронизация
работают на PHP-хостинге. Настройки: main, корень репозитория,
Build command: npm run build, Deploy command: npx wrangler deploy.
wrangler.jsonc использует assets.directory=./dist. Правила и проверка публикации:
specs/cloudflare-deployment.md. Для отдельного Pages-проекта есть wrangler.pages.jsonc.

## Проверки и экспорт

```sh
php artisan site:export
npm run build
npm test
php artisan test
php tests/php/run.php
npx wrangler@4.151.0 deploy --dry-run
git diff --check
```

Сохраняйте свежий public/dist экспорт вместе с исходниками. Cloudflare без PHP
использует committed экспорт; Node останавливается, если отпечаток Blade не совпал.
HTML нельзя править вручную. .gitattributes помечает экспорт linguist-generated,
поэтому он не искажает статистику исходных языков GitHub. JavaScript остаётся
исходным кодом интерактивных карт; Laravel не заменяет браузерный JavaScript.
