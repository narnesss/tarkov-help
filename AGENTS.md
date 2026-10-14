# Tarkov Wiki: правила проекта

Активный план: `specs/002-alwaysdata-api/plan.md`.
Перед изменениями сборки/CI/Wrangler и любым деплоем читать `specs/cloudflare-deployment.md`.

- Основа — Laravel 13; PHP production/CI — 8.4, минимальная версия — 8.3.
- Страницы: resources/views/*.blade.php, конфигурация config/pages.php; HTML — экспорт.
- Laravel управляет HTTP/DI, Blade, Cache, DB migrations и Artisan.
- API-доменные сервисы src сохраняют существующие opaque cookie/HMAC CSRF/CAS-контракты.
- Общий кэш содержит только публичные данные; API и личный прогресс — private, no-store.
- Cloudflare Workers публикует dist и не запускает PHP. Полный API требует PHP-хостинга.
- Для API/аккаунтов/deploy читать specs/alwaysdata-deployment.md: Laravel/SQLite на alwaysdata,
  Worker — same-origin proxy с секретом. API_ENABLED управляется панелью, keep_vars сохранять.
- Не доверять forwarded IP без секрета; APP_URL — HTTPS origin сайта Cloudflare.
- SQLite вне релиза; при обновлении сохранять .env/APP_KEY и делать согласованную backup.
- Composer vendor, .env, storage и БД не коммитить; composer.lock коммитить.
- После изменений: composer install, php artisan site:export, npm run build, npm test,
  php artisan test, php tests/php/run.php, npx wrangler@4.151.0 deploy --dry-run, git diff --check.
- Blade/public/dist обновлять вместе; проверять отказ сборки при устаревшем экспорте.
- Коммиты, документация и новые PHPDoc — по-русски. Обычные push без force.
- При изменении архитектуры обновлять docs/architecture/class-map.md и database-map.md.
