# Карта классов Tarkov Wiki

- bootstrap/app.php, public/index.php: Laravel 13 HTTP kernel; artisan: CLI.
- routes/api.php: известные API и публичные GET/HEAD маршруты.
- AppServiceProvider: DB/Clock/AccountService/StateRepository/Application DI.
- ApiController: Laravel Request/Response ↔ доменный API. Application проверяет
  HMAC CSRF, origin, cookie owner, JSON и формирует private/no-store ответы.
- VerifyApiProxy: проверяет секрет Worker до API и передаёт подтверждённый IP клиента.
- config/api.php: API_ONLY и обязательная защита PHP origin для alwaysdata.
- cloudflare/worker.mjs: ASSETS для сайта, same-origin API proxy, динамический
  profile-runtime.mjs (только boolean, без секретов), private/no-store и JSON 503.
- bootstrap.mjs: включает серверные профили по runtime-модулю, сохраняет статический роутинг.
- package-alwaysdata.mjs: минимальный архив API; deployment/alwaysdata: production env,
  проверка платформы, сохранение APP_KEY/SQLite, миграции и кэш.
- release.sh: GitHub Actions SSH-публикация проверенного API-артефакта, общие .env/storage,
  переключение релизов после подготовки и HTTP-проверка с возвратом предыдущего кода.
- prune-releases.php: ограниченная очистка старого кода без следования по симлинкам.
- PageController: публичный PageCatalog → PageViews, ETag/HEAD/304/404, без сессии.
- PageCatalog: JSON-данные → полный маршрутный план, Laravel Cache по hash JSON.
- PageViews: Blade + Laravel Cache по hash исходников/runtime/title/params.
- ExportSite: Artisan site:export → public HTML, route manifest и fingerprint.
- resources/views: общий layout, страницы и заголовки; источник разметки PHP.
- src/Identity, Profile: существующие проверенные SQLite-сервисы и CAS контракты.
- src/View/PageRenderer: совместимый Laravel-адаптер старых доменных регрессий.
- src/Infrastructure/Database и Factory: совместимость автономных доменных тестов;
  production подключение и схема предоставляются Laravel DB/migrations.
- scripts/build-pages.mjs: проверка свежести экспорта, упаковка dist без PHP/секретов.
