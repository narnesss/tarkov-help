# Карта классов Tarkov Wiki

- WikiSchema / WikiStore: белый список предметных таблиц, типизированные поля/FK,
  имена для Unicode-поиска, стабильный upsert и происхождение исходных файлов.
- Models/Wiki: Eloquent Item, Key, Location, Map, MapLayer, MapPoint, Document,
  Quest, Objective, ObjectiveGroup, PrerequisiteGroup, Trader, RewardSet, Version;
  Record поддерживает также таблицы-связки с составным ключом, Options — поиск Orchid.
- WikiHomeScreen / WikiListScreen / WikiEditScreen: каталоги, поиск, создание,
  связанные списки от выбранного владельца; все FK выбираются по названию.
- WikiEditor: валидация FK, координат, этажей, режимов, дерева и DAG квестов;
  CAS revision, архив вместо удаления, транзакция SQLite вместе с файловыми ссылками.
- WikiImporter / ImportWikiDomain: wiki:import-domain, backfill 5634 предметов,
  1226 квестов и 19980 точек исходного каталога; повтор по SHA, прежние ID и файлы
  сохраняются, неизвестные ссылки регистрируются текстом.
- WikiSync: защищает dual-write от рекурсии; прежние формы синхронизируют предметные
  строки, WikiPublisher публикует изменённые разделы в совместимые файлы.
- WikiGraph: SQL-каталоги и детали точек/квестов на /api/content/data/wiki/*.json,
  фильтры локации/режима/категории, деревья условий и награды; private/no-store.

- Orchid PlatformProvider / routes/platform.php: админка PHP `/admin`, права `wiki.content`.
- User / UserPresenter / AdminSession: отдельные редакторы `admin_users`, DB-сессии
  `admin_sessions`; стандартные Laravel cookie/CSRF, private/no-store для экранов и ошибок.
  AdminSession выполняется глобально только для `/admin/*` до конструирования контроллеров,
  чтобы guard Orchid и StartSession использовали одно хранилище, включая вход без remember.
- QuestListScreen / QuestEditScreen / QuestEditor / TraderCatalog: формы PVE/PVP, названия
  торговцев и выбор из справочника; текстовые цели, защита ID прогресса и устаревших форм.
- ContentFields / ContentFieldsScreen / QuestFieldsScreen / DatasetEditScreen: переходы по
  вложенным разделам, текст/числа/да-нет, готовые варианты добавления без ручных ID и JSON.
  Кнопки сохраняют section/page; состояние Orchid содержит только публичные свойства,
  типизированные защищённые свойства имеют значения по умолчанию для безопасного wakeup.
- ContentLinks / ReferenceOptions / ContentReference: Orchid Relation по названиям предметов,
  локаций, квестов, ключей, торговцев, документов, этажей и прочих справочников; серверный
  поиск с русским регистром и английскими псевдонимами, выдача до 30 записей, право wiki.content.
  Выбор проверяет тип/режим и возвращает прежний игровой ID; составные объекты и группы
  условий сохраняют форму. Большие списки раскрываются по 60 связей, без обрезки PHP input.
- ReferenceIndexer / IndexContentReferences: пересоздаваемый индекс обычных колонок из
  файлов контента. Обновление при сохранении; wiki:reference-index после импорта при deploy.
- Quest / ContentDataset / ContentFiles: неизменяемые gzip JSON-файлы рядом с SQLite;
  в колонке payload только @file:SHA256, запись файла атомарна и предшествует CAS-обновлению БД.
  Старые версии сохраняются для снимков. Чтение прежнего JSON оставлено для перехода/rollback.
- ContentFileStorage: wiki:content-files --move, идемпотентный перенос прежних значений
  с проверкой файлов; не меняет ID, тексты, черновики и даты редакторских правок.
- prepare-content-seed.mjs / ImportContent: первичный перенос 63 наборов и 1226 записей
  режимов, обогащение прежними клиентскими правилами; повтор не перезаписывает редакторов.
- ContentController / ContentQuestController: индекс контента из SQLite, содержимое из файлов и проверка
  существования квеста/торговца, защищённые VerifyApiProxy, без сессии и общего кэша.
- content-client.mjs / guide-common.mjs / app.mjs: свежие JSON CMS перед статическими снимками;
  quests.mjs использует окончательные задания CMS без повторного статического наложения.
- Worker: новые URL квестов/торговцев проверяются в Laravel, затем получают статическую
  оболочку; run_worker_first дополнен `/quests/*`, остальные ассеты идут напрямую ASSETS.
- Composer публикует Orchid assets на PHP origin; build-pages исключает их из dist.
  Документация заполнения и первоначального запуска: docs/orchid-content.md.

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
- BackupSqlite / SqliteBackups: Artisan wiki:backup, VACUUM INTO/quick_check, приватные
  daily/deploy снимки, общий lock и лимиты числа/объёма. prepare.php использует этот же сервис.
- RestoreSqlite / SqliteBackups: список имён/SHA-256, проверка схемы и FK, снимок текущей БД,
  восстановление SQLite3::backup без замены файла, возврат safety-копии при ошибке проверки.
- DatabaseAccess / GuardDatabaseRestore: shared flock на время API/бэкапа, exclusive при restore;
  маркер незавершённой операции сохраняет private JSON 503 до безопасного восстановления.
- restore-alwaysdata.yml / restore.sh: только workflow_dispatch main, list/check/restore,
  явное подтверждение замены данных; общая с деплоем и daily concurrency.
- check-restore.php / test-restore.sh: реальные BackupSqlite/RestoreSqlite и миграция в отдельном
  Laravel-контейнере с собственной SQLite; production bootstrap/.env/config cache не читаются.
  CI проверяет цикл локально и после deploy на alwaysdata; test-restore-alwaysdata.yml повторяет его вручную.
- deployment/alwaysdata/backup.sh: ежедневное задание alwaysdata через стабильный tarkov-api.
- backup-alwaysdata.yml: ежедневный запуск 03:30 Europe/Moscow через SSH, общая с deploy
  concurrency; БД остаётся на alwaysdata, ручной Run workflow только для main.
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
