# Tarkov Wiki: правила проекта

Активный план: `specs/002-alwaysdata-api/plan.md`.
Перед изменениями сборки/CI/Wrangler и любым деплоем читать `specs/cloudflare-deployment.md`.

- Основа — Laravel 13; PHP production/CI — 8.4, минимальная версия — 8.3.
- Страницы: resources/views/*.blade.php, конфигурация config/pages.php; HTML — экспорт.
- Laravel управляет HTTP/DI, Blade, Cache, DB migrations и Artisan.
- API-доменные сервисы src сохраняют существующие opaque cookie/HMAC CSRF/CAS-контракты.
- Общий кэш содержит только публичные данные; API и личный прогресс — private, no-store.
- Cloudflare Workers публикует dist и не запускает PHP. Полный API требует PHP-хостинга.
- Для API/аккаунтов/deploy читать specs/alwaysdata-deployment.md: Laravel/MariaDB на alwaysdata,
  Worker — same-origin proxy с секретом. API_ENABLED управляется панелью, keep_vars сохранять.
- Не доверять forwarded IP без секрета; APP_URL — HTTPS origin сайта Cloudflare.
- Production: MySQL-протокол/MariaDB, JSON-контент — CONTENT_DIRECTORY вне релиза; SQLite — локальная совместимость и сохранённый источник переноса.
- При обновлении сохранять .env/APP_KEY, ID/связи/аккаунты/прогресс и делать согласованный SQL backup; старую SQLite после переключения не открывать для записи.
- Composer vendor, .env, storage и БД не коммитить; composer.lock коммитить.
- После изменений: composer install, php artisan site:export, npm run build, npm test,
  php artisan test, php tests/php/run.php, npx wrangler@4.151.0 deploy --dry-run, git diff --check.
- Blade/public/dist обновлять вместе; проверять отказ сборки при устаревшем экспорте.
- Коммиты, документация и новые PHPDoc — по-русски. Обычные push без force.
- Коммиты и публикации дробить на небольшие самостоятельные изменения по правилам
  `.cursor/rules/incremental-deployment.mdc`; следующий релиз — после проверки предыдущего.
- При изменении архитектуры обновлять docs/architecture/class-map.md и database-map.md.
- Все новые формы и поисковые поля: правила `.cursor/rules/form-input-security.mdc` обязательны.
  Нормализовать обычный текст, валидировать независимо на фронтенде и сервере; SQL — только с параметрами,
  вывод — с экранированием. Пароли, токены, HMAC/CSRF и стабильные ID автоматически не обрезать.
- Для основного и боковых чатов соблюдать `.cursor/rules/side-chat-handoff.mdc`:
  передавать путь рабочей копии, ветку/SHA, незакоммиченные файлы и проверки;
  при объединении сверять весь список задач и проверять публикацию каждого этапа.
- Перед каждой следующей задачей проверять готовые правки боковых чатов и сначала
  объединять найденное; если переносить нечего, сразу приступать к новой задаче.
- Боковые чаты сохраняют передачу в build/side-chat-handoffs/<тема>.json основной копии;
  основной чат читает эти отчёты и прежние build/*-ready.md до начала новой реализации.
