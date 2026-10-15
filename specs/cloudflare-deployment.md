# Правила публикации Cloudflare

Читайте этот файл перед изменением сборки, Wrangler, CI или публикацией сайта.

- Основной проект использует Workers Static Assets, а не Pages.
- Ветка: `main`; корень: корень репозитория.
- Build command: `npm run build`; Deploy command: `npx wrangler deploy`.
- Worker: tarkov-compass; публичный origin https://tarkov-compass.narnesss.workers.dev.
- `wrangler.jsonc`: main=cloudflare/worker.mjs, `assets.directory = ./dist`, `html_handling = drop-trailing-slash`,
  `not_found_handling = 404-page`. Не добавлять сюда `pages_build_output_dir`.
- Публикуется только `dist`. PHP, vendor Composer, .env, базы и storage туда не попадают.
- Workers не исполняет Laravel/PHP: он обслуживает готовый статический экспорт.
  Laravel API запускается на alwaysdata PHP 8.4 с корнем `public`; детали обязательны
  в specs/alwaysdata-deployment.md. /api/* и /profile-runtime.mjs идут через Worker;
  `/quests/*` проверяет отсутствующие в экспорте квесты/торговцев и выдаёт общую оболочку;
  остальные маршруты обслуживаются ASSETS. Не включать run_worker_first для всех ассетов.
- API_ORIGIN=https://narnes.alwaysdata.net; API_PROXY_TOKEN — Secret, не Git.
  API_ENABLED=true задаётся в панели после готовности origin; по умолчанию локальные профили.
  keep_vars=true сохраняет ручную настройку, не добавлять API_ENABLED в vars репозитория.
- Источник страниц — `resources/views/**/*.blade.php`. Выполнять `composer install`,
  `php artisan site:export`, `npm run build`, `npm test`, `php artisan test`,
  `npx wrangler@4.151.0 deploy --dry-run`, `git diff --check` перед push.
- HTML в `public` и `dist` — генерируемые файлы. Править Blade и повторять экспорт.
- Node-сборка без PHP использует проверенный экспорт: при изменении исходников
  без повторного экспорта сборка обязана остановиться, а не публиковать старый HTML.
- Личные профили и API имеют `private, no-store`; общий кэш хранит только публичные данные.
- fetch к PHP origin обязательно использует cache: 'no-store'. Не ставить cf.cacheTtl=0:
  это принудительное кэширование с мгновенным истечением, оно может убрать Set-Cookie.
  После изменения proxy проверять cookie, регистрацию/вход и сохранение через живой Worker.
- Обычный push, без force; после push проверять CI конкретного SHA.
- Успешный dry-run/CI не означает успешную публикацию Cloudflare. Проверять её отдельно.
- Pages — отдельный вариант: конфигурация `wrangler.pages.jsonc`, каталог `dist`.
  Нельзя запускать `wrangler deploy` с конфигурацией Pages.

Источники: https://developers.cloudflare.com/workers/static-assets/binding/
и https://developers.cloudflare.com/workers/wrangler/commands/workers/.
