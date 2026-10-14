# Tarkov Wiki: правила проекта

Активный план: `specs/001-laravel-13/plan.md`.
Перед изменениями сборки/CI/Wrangler и любым деплоем читать `specs/cloudflare-deployment.md`.

- Основа — Laravel 13; PHP production/CI — 8.4, минимальная версия — 8.3.
- Страницы: resources/views/*.blade.php, конфигурация config/pages.php; HTML — экспорт.
- Laravel управляет HTTP/DI, Blade, Cache, DB migrations и Artisan.
- API-доменные сервисы src сохраняют существующие opaque cookie/HMAC CSRF/CAS-контракты.
- Общий кэш содержит только публичные данные; API и личный прогресс — private, no-store.
- Cloudflare Workers публикует dist и не запускает PHP. Полный API требует PHP-хостинга.
- Composer vendor, .env, storage и БД не коммитить; composer.lock коммитить.
- После изменений: composer install, php artisan site:export, npm run build, npm test,
  php artisan test, php tests/php/run.php, npx wrangler@4.151.0 deploy --dry-run, git diff --check.
- Blade/public/dist обновлять вместе; проверять отказ сборки при устаревшем экспорте.
- Коммиты, документация и новые PHPDoc — по-русски. Обычные push без force.
- При изменении архитектуры обновлять docs/architecture/class-map.md и database-map.md.
