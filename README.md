# Escape from Tarkov Wiki — Laravel 13

Источник сайта — PHP/Laravel 13 и Blade. Карты, фильтры, галереи и личный прогресс
в браузере продолжают использовать существующие JavaScript-модули.

## Структура

- app, bootstrap, routes, config — Laravel HTTP, DI, Cache и Artisan.
- resources/views — Blade/PHP, общий layout и страницы.
- src — проверенные доменные сервисы аккаунтов, CSRF и сохранения прогресса.
- database/migrations — актуальная схема MariaDB и локальная совместимость SQLite; composer.lock фиксирует зависимости.
- public — web root, исходные браузерные модули/ассеты и экспорт оболочек.
- dist — сгенерированная статическая публикация Cloudflare.
- specs/cloudflare-deployment.md — обязательные правила публикации для будущих изменений.

## Локальный запуск PHP

Laravel 13 поддерживает PHP >= 8.3; production/CI — PHP 8.4 с PDO MySQL, PDO SQLite для совместимости, mbstring,
curl, XML и стандартными расширениями Laravel. Корень веб-сервера — public.

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
php artisan serve
```

Этот пример — локальный SQLite: в .env задать DB_CONNECTION=sqlite и путь DB_DATABASE.
Существующая локальная БД: DB_DATABASE=/absolute/path/var/wiki.sqlite перед migrate.
Сделайте резервную копию; миграция принимает существующие таблицы и не удаляет записи.
APP_STORAGE совместим с прежним размещением БД. APP_KEY обязателен; храните его
и .env вне Git, используйте APP_URL с настоящим HTTPS origin.

Production: APP_DEBUG=false, composer install --no-dev --optimize-autoloader,
php artisan migrate --force, php artisan optimize. При обновлении сначала
php artisan optimize:clear, затем optimize. Нужны права записи только в storage
и bootstrap/cache. Локальный исторический старт: specs/001-laravel-13/quickstart.md;
действующая production-процедура — specs/alwaysdata-deployment.md.

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

Workers публикует статический dist и проксирует /api/* в Laravel на alwaysdata.
Основной сайт: https://tarkov-compass.narnesss.workers.dev; PHP origin: https://narnes.alwaysdata.net.
Серверные аккаунты включаются переменной API_ENABLED=true в панели Cloudflare только
после настройки общего API_PROXY_TOKEN и готовности origin. Без настройки доступны
локальные профили. Настройки сборки: main, корень репозитория,
Build command: npm run build, Deploy command: npx wrangler deploy.
wrangler.jsonc использует assets.directory=./dist. Правила и проверка публикации:
specs/cloudflare-deployment.md. Для отдельного Pages-проекта есть wrangler.pages.jsonc.

## alwaysdata: загрузка Laravel API

Полная инструкция с вашими адресами: [specs/alwaysdata-deployment.md](specs/alwaysdata-deployment.md).
В панели выбрать PHP 8.4, включить SSH, web root /home/narnes/tarkov-api/public.
`npm run package:alwaysdata` создаёт build/alwaysdata-api.tar.gz без vendor, dist,
карт и личных данных. После загрузки на сервер скопировать production environment.example
в .env, задать секрет, выполнить `sh deployment/alwaysdata/deploy.sh`.
Production использует MariaDB: DB_CONNECTION=mysql, база narnes_tarkov на
mysql-narnes.alwaysdata.net. SQL backup — native SQL gzip + manifest; для восстановления
нужен соответствующий CONTENT_DIRECTORY=/home/narnes/tarkov-data/content.
Прежняя wiki.sqlite — сохранённый источник переноса, для записи не используется.
При обновлении сохраняются .env, APP_KEY, ID, связи, аккаунты и прогресс.

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

## Действующие контракты

- [Источники истины, схема, публикация и импорт контента](specs/data-authority-and-publication.md).
- [Совместимость фронтенда/API, ресурсы и конфликты профиля](specs/client-api-contract.md).
- [Восстановление MariaDB вместе с контентом](specs/recovery-contract.md).
- [Аудит и измеримые бюджеты производительности](specs/audit-and-performance.md).

Статусы реализации и проектные цели указаны в каждом документе; новые требования
не означают, что соответствующие механизмы уже внедрены. Планы первоначального
SQLite-развёртывания не заменяют текущие миграции и production-runbook.
