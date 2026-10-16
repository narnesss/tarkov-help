# Карта классов Tarkov Wiki

- Атлас документов сверяется с kord-map-mtdv.ru. document-point-provenance содержит исходные
  ID, координаты/viewBox, этажи и контрольные суммы снимка. SVG-панели этажей имеют отдельные
  пространства координат: spawns.x/y — проценты соответствующей панели общего изображения.
  ContentUpdates проверяет прежние поля перед заменой, сохраняет ID и дополнительные связи;
  устаревшие записи архивируются. Актуальный seed допускается и при первом развёртывании.
  model.sanitizeProgress сохраняет архивные ID из source.retiredPointIds, но не включает
  их в счётчик актуальных точек. Применённый пакет не перезаписывает последующие правки.

- ContentUpdates / ApplyContentUpdates применяют проверенные пакеты database/content-updates
  один раз под WikiSync и транзакцией: сохраняют ID/порядок целей, остальные поля и прогресс.
  Повторный релиз пропускает уже применённый хеш и не затирает последующие правки редактора.
  WikiImporter::changedPoints обновляет только затронутые точки при актуальной прежней проекции.
  pointExternal / repairPointScopes разделяют совпадающие служебные ID разных локаций,
  сохраняя прежние SQL ID/FK и публичные ID; публикация использует отдельные wiki_sources.
- questBriefing показывает прохождение отдельно от исходного диалога; quest-map-index содержит
  метки в координатах конкретной схемы, без подмены мировых координат. Gallery связывает фото
  с существующими целями. raid-scope проверяет карту/режим и исключает архивные/неподтверждённые
  внешние точки. ContentController скрывает их также в API; правка координат в WikiPublisher
  снимает пометку проверки, после чего новая revision возвращает точку на сайт.

- PlayerPasswordRecovery / ResetPlayerPassword: восстановление через подтверждённый player_emails,
  код на 1 час с HMAC/5 попытками и привязкой к opaque-сессии запроса. Одинаковые ответы
  для неизвестных адресов; SMTP после HTTP-ответа под отдельной shared-блокировкой DatabaseAccess.
  Смена только users.password_hash, отзыв прежних sessions, OAuth-состояний и почтовых кодов.
  AccountService::login сериализует пароль/выдачу сессии с обновлением users; OAuth и PlayerEmails
  повторно проверяют сессию под player_auth_locks, чтобы завершённый сброс нельзя было обойти.

- PlayerEmails / VerifyPlayerEmail: адрес и одноразовый код в личном кабинете, CSRF/Origin,
  HMAC кода, TTL/попытки/лимиты, транзакционное назначение email прежнему ID. Laravel Mail
  отправляет письмо через TLS SMTP alwaysdata; log/failover/array запрещены в production.
  Тема письма — английский ASCII, тело — русский UTF-8. PlayerOAuth учитывает player_emails при выборе существующего аккаунта.

- PlayerAuthController / PlayerOAuth: Google/Discord через защищённые JSON POST, одноразовый
  state с привязкой к opaque-сессии, identity provider/subject и явное подключение к игроку.
  Токены провайдеров не сохраняются; AccountService::issueFor выдаёт прежний cookie-контракт.
- PlayerProfiles: имя/аватар провайдера и пользовательское отображаемое имя отдельно от
  логина; обогащение ответов API и CSRF-защищённое редактирование. PlayerOAuth сохраняет
  подтверждённую почту, автоматически связывает допустимое однозначное совпадение под
  player_auth_locks FOR UPDATE; внешний Google email требует входа в прежний аккаунт.
- PlayerOAuthSettings / oauth-transfer.sh / player-oauth.yml: ограниченная установка
  OAuth-реквизитов через RSA-шифротекст; атомарная правка только четырёх переменных .env.
- account.blade.php / account.mjs / account-model.mjs: отдельный кабинет, формы входа/регистрации,
  сводка PVE/PVP по актуальным ID каталога; чтение личных данных через ProfileStore.
  player-navigation.blade.php / profile-navigation.mjs — нейтральная ссылка кабинета в исходном HTML;
  кнопки входа/регистрации скрыты до подтверждения гостевой сессии, включая отказ API.

- MysqlMigration / MigrateMysql: пробный и финальный перенос всех таблиц SQLite в MariaDB,
  сохранение PK/rowid/sequence, сверка SHA-256 каждой строки, FK и файлов контента;
  закрытие старого источника, сохранение .env/APP_KEY и атомарное переключение config cache.
- SqlDatabaseAudit: переносимые спецификации строк, канонические SHA-256, FK anti-join,
  контроль gzip-файлов контента и отпечаток схемы MariaDB.
- MysqlBackups: native single-transaction SQL gzip-снимок, приватный defaults-файл,
  retention/манифест/SHA и safety restore. BackupSqlite/RestoreSqlite выбирают сервис по драйверу.
- WikiSchema: MySQL numeric id или сохранённый физический rowid для составных PK;
  SQLite PRAGMA и MySQL Schema introspection предоставляют одинаковые поля именованных форм.
- WikiSync: сериализует редактирование графа через wiki_write_locks FOR UPDATE на MariaDB,
  чтобы одновременные изменения не обошли проверку циклов.
- mysql-transfer.sh / migrate-mysql.yml: штатный SSH CI и RSA-OAEP-SHA256 реквизиты;
  GitHub получает только публичный ключ/шифротекст. verify-mysql.php проверяет драйвер FPM.

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
  строки, WikiPublisher публикует изменённые разделы в совместимые файлы. Дополнительные
  количества, вероятности, способы доступа, медиа и все позиции сохраняются в файловых
  cms-полях; прежние текстовые поля согласуются с ними при правке через старые формы.
- WikiGraph: SQL-каталоги и детали точек/квестов на /api/content/data/wiki/*.json,
  фильтры локации/режима/категории, деревья условий и награды; private/no-store.
  WikiPublisher освобождает распакованный справочник после файловой записи до индексации,
  чтобы сохранение предмета сразу в PVE/PVP укладывалось в память PHP-хостинга.
  Формы контента запрашивают полную загрузку после переходов Turbo, чтобы повторное
  сохранение не оставалось без обработчика после замены DOM; серверные проверки сохраняются.

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

- PasswordPolicy: общие правила новых паролей в AccountService::register и PlayerPasswordRecovery;
  public/password-policy.mjs проверяет форму до запроса. Вход с прежними паролями не меняется.
  История проверяется только после правильного кода, в транзакции под player_auth_locks/users.

- PlayerProfiles закрепляет имя и аватар аккаунта. PlayerOAuth::finish вызывает initializeOAuth
  только для впервые созданного внешнего аккаунта; привязка и повторный вход не меняют профиль.
  Переименование выполняется под блокировкой авторизации, проверяет актуальную opaque-сессию.
- PlayerAdmin / PlayerCredentials, модель Player и Orchid PlayerListScreen/PlayerEditScreen:
  право wiki.users, CRUD игроков с CAS, безопасная смена пароля и отзыв сессий.
  Аккаунты admin_users изолированы; публичный API управления игроками не добавляется.
- profile-identity.mjs обновляет тот же профиль после сохранения имени без reload.
  Bootstrap сохраняет подтверждённую личность при сбое загрузки прогресса; hidden имеет приоритет CSS.

- form-feedback.mjs связывает локальные и серверные ошибки с полями, фокусом и доступными сообщениями.

- admin-user-validation.mjs: предварительная проверка формы Orchid и общий PasswordPolicy в браузере;
  серверные PlayerEditScreen/PlayerAdmin повторяют проверку структуры и значений каждого POST.

- AdminInput: нормализация обычного текста и поиска, проверка текста/URL и конечности чисел.
  WikiEditor, QuestEditor и ContentFields применяют её до записи, независимо от браузера.
  admin-content-validation.mjs показывает ошибки рядом с полями; схемы и ограничения задаются формой.
  Нормализация не затрагивает API/HMAC, пароли, CAS-подписи и стабильные ID.
  ProtectAdminRoute запрещает подмену route ID через query; AdminRelationRequest проверяет
  типы, размер и целостность зашифрованных параметров выбора по названию до контроллера Orchid.

- AdminNavigation и catalog-tabs.blade.php формируют вкладки обычными ссылками; PHP выводит
  только выбранные режим, подкатегорию и справочник, без скрытых JS-панелей.
- ItemCatalog строит категории по типам файлового каталога и кэширует только публичные ID
  по версиям файлов. Нестандартные прежние связи ключей доступны отдельно без удаления строк.
- DatasetCatalog связывает понятные названия и разделы с прежними ID/путями ContentDataset.
  TraderCatalog берёт имена из предметной SQL-проекции, не распаковывая полный каталог квестов.
- ContentController кэширует только публичный JSON каталога, главной и справочников в Laravel Cache.
  Сжатый слот на путь изолирован по БД и каталогу файлов. Перед повторным использованием
  проверяется снимок SQL-ссылок опубликованных квестов: правки и снятие с публикации видны сразу.
  TTL — 10 минут; при недоступном кэше ответ собирается штатно. API сохраняет private, no-store.
- reference-loader.mjs загружает базовые каталоги справочника параллельно; вспомогательные
  материалы квестов запрашивает только для автономного снимка без признака cms.
- ContentController::bundle объединяет только фиксированные публичные наборы quest-extras и raid-base.
  Одна проверка SQL-версий и сжатый слот кэша заменяют очередь мелких PHP-запросов.
  content-bundles.mjs сохраняет статический fallback; raid.mjs загружает базу карты и дополнения параллельно.
- page-data-preload.mjs начинает один публичный запрос параллельно с подтверждением профиля.
  guide-common/json-preload передают этот запрос первому потребителю; обновление и запрос с AbortSignal
  остаются свежими. Запуск UI по-прежнему ждёт профиль и прогресс, секреты в предзагрузку не входят.

- LegalConsents проверяет отдельные подтверждения, версию документов и фиксирует три записи.
  Laravel DI оборачивает доменную регистрацию в транзакцию с записью согласий; OAuth переносит
  версию через одноразовый state и проверяет её до обмена code. При входе и подключении сервиса
  к существующему аккаунту согласия повторно не запрашиваются. Создание нового OAuth-аккаунта
  без подтверждений отклоняется с переходом к регистрации. legal-consent.mjs помогает форме,
  сервер повторяет проверку независимо. Чтение правовых страниц не создаёт гостевую сессию.
  account.mjs не предлагает вручную вводить почту: PlayerOAuth ищет владельца по подтверждённым
  адресам провайдеров и сохранённым player_emails. Прежние адреса для восстановления сохраняются.

- PublicCatalog формирует облегчённые списки квестов/предметов и справочники без метаданных карт.
  ContentController проверяет view/id и публикацию; detail возвращает одну полную карточку.
  lookup содержит только карты и квестовые предметы, без связей всех заданий.
- page-data.mjs расходует публичную предзагрузку однократно. Карточки, иллюстрации точек,
  сведения о боссах и БТР запрашиваются при открытии; старый сервер и статика сохраняют fallback.
- public-content-cache.mjs хранит только публичные JSON в отдельной IndexedDB (32 МиБ/24 записи).
  Перед повторным использованием обязательна малая проверка revision; при правке, архивировании
  или снятии с публикации загружается новая версия. API Response/cookies/CSRF/прогресс не хранятся.
  ContentController::FORMAT меняется при изменении формата представлений; HTTP остаётся no-store.

`QuestGuideUpdate` добавочно публикует проверенные материалы 21 квеста под общим WikiSync lock. Проверка ID целей и версия дополнения защищают прежние цели и редакторский контент.
