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

## Cloudflare Pages

Production branch: `main`. Framework: None. Build command: `npm run build`.
Output directory: `dist`. `wrangler.jsonc` also declares `dist` as the output.
No Wrangler installation or token is required on a visitor's computer.

`dist` is committed so the first deployment can serve the complete site even
if the current dashboard still uses `exit 0` as its build command. For future
updates, set the dashboard build command to `npm run build`.

Pages uses browser-local profiles. The PHP account/session API runs only on
a PHP host; it is not part of the static deployment. Export/import a profile
to move progress between domains or devices.

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
