# Правила публикации Cloudflare

Читайте этот файл перед изменением сборки, Wrangler, CI или публикацией сайта.

- Основной проект использует Workers Static Assets, а не Pages.
- Ветка: `main`; корень: корень репозитория.
- Build command: `npm run build`; Deploy command: `npx wrangler deploy`.
- `wrangler.jsonc`: `assets.directory = ./dist`, `html_handling = drop-trailing-slash`,
  `not_found_handling = 404-page`. Не добавлять сюда `pages_build_output_dir`.
- Публикуется только `dist`. PHP, vendor Composer, .env, базы и storage туда не попадают.
- Workers не исполняет Laravel/PHP: он обслуживает готовый статический экспорт.
  Полный Laravel/API запускается на отдельном PHP 8.4-хостинге с корнем `public`.
- Источник страниц — `resources/views/**/*.blade.php`. Выполнять `composer install`,
  `php artisan site:export`, `npm run build`, `npm test`, `php artisan test`,
  `npx wrangler@4.151.0 deploy --dry-run`, `git diff --check` перед push.
- HTML в `public` и `dist` — генерируемые файлы. Править Blade и повторять экспорт.
- Node-сборка без PHP использует проверенный экспорт: при изменении исходников
  без повторного экспорта сборка обязана остановиться, а не публиковать старый HTML.
- Личные профили и API имеют `private, no-store`; общий кэш хранит только публичные данные.
- Обычный push, без force; после push проверять CI конкретного SHA.
- Успешный dry-run/CI не означает успешную публикацию Cloudflare. Проверять её отдельно.
- Pages — отдельный вариант: конфигурация `wrangler.pages.jsonc`, каталог `dist`.
  Нельзя запускать `wrangler deploy` с конфигурацией Pages.

Источники: https://developers.cloudflare.com/workers/static-assets/binding/
и https://developers.cloudflare.com/workers/wrangler/commands/workers/.
