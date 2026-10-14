# Project workflow

This repository is the working source for the Tarkov site. Keep PHP 8.4
source and the Cloudflare Pages export together in each change.

- PHP code: `src`, `bootstrap.php`, `config`, `templates`, `bin`, `public/index.php`.
- Browser code, assets and data: `public`.
- Pages publishes `dist` using the tracked `wrangler.jsonc`.
- After source changes run `php bin/console export:static`, `npm run build`,
  `npm test`, `php tests/php/run.php` and `git diff --check`.
- Commit the refreshed `dist` output together with PHP/template changes.
- Pages has browser-local profiles; server accounts require PHP hosting.
- Keep runtime databases, sessions, keys and environment secrets out of Git.
- Preserve existing user changes and use normal, non-forced pushes.
