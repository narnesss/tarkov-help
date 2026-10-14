# Escape from Tarkov Wiki

PHP 8.4 application and its ready-to-publish Cloudflare Pages export.

## Layout

- `src`, `bootstrap.php`, `config`, `templates`, `bin` — PHP source.
- `public` — PHP web root, browser modules, maps, images and data.
- `dist` — static Pages export, without PHP or private runtime data.
- `tests/php` — session, account, state and request tests.
- `scripts` — Pages build and validation.

The current full-page PHP templates match the interface previously uploaded
to GitHub. The builder exports these templates and generates physical quest,
trader, map, boss and item pages from `public/data/page-routes.json`.

## Cloudflare Workers

Для проекта с командой публикации `npx wrangler deploy`:

- Ветка: `main`.
- Команда сборки: `npm run build`.
- Команда публикации: `npx wrangler deploy`.
- Корневая папка: корень репозитория.

`wrangler.jsonc` указывает на `dist` через `assets.directory`. Workers публикует
готовые HTML, JavaScript, карты и изображения. Чистые URL обслуживаются без
завершающего слеша; неизвестные страницы возвращают `404.html` со статусом 404.
Файлы `_headers` и `_redirects` сохраняют заголовки и старые адреса.

`dist` включён в Git, поэтому публикация использует готовую сборку даже без
PHP в окружении Cloudflare. PHP-исходники остаются в репозитории; серверный API
аккаунтов запускается только на PHP-хостинге. На Cloudflare профили сохраняются
в браузере. Для переноса прогресса используйте экспорт и импорт профиля.

## Cloudflare Pages (альтернативная публикация)

Для отдельного проекта Pages: ветка `main`, Framework: None, команда сборки
`npm run build`, папка публикации `dist`. Через CLI используйте:

```sh
npx wrangler pages deploy dist --config wrangler.pages.jsonc --project-name tarkov-help
```

Конфигурация Pages хранится отдельно в `wrangler.pages.jsonc`, чтобы обычная
команда Workers `wrangler deploy` не принимала её за конфигурацию Worker.

## Updating source

```sh
php bin/console export:static
npm run build
npm test
php tests/php/run.php
```

Commit source and the refreshed `public`/`dist` output together. GitHub Actions
checks PHP 8.4 behavior and verifies the committed export against the source.
The Node builder supports the current full-page templates' runtime marker;
additional PHP expressions must first be exported using the PHP CLI.

## PHP hosting

Requires PHP 8.4 with PDO SQLite, mbstring, curl and JSON. Set the web server's
document root to `public` and enable the supplied Apache rewrite rules.
Set `APP_URL` to the origin and keep `APP_STORAGE`/`APP_KEY` outside `public`.
Run `php bin/console install` to initialize storage.
Runtime databases, sessions and application keys are ignored by Git.
