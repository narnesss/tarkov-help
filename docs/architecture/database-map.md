# Карта БД Tarkov Wiki

Миграция `2026_10_16_000008_add_player_email_verification.php` добавляет:

- `player_emails`: PK/FK user_id, unique email, verified_at. Один подтверждённый контакт
  на прежний ID; смена только после кода. До подтверждения новый адрес не заменяет старый.
- `player_email_challenges`: PK/FK user_id, unique challenge_id, email, code_hash HMAC-SHA256,
  attempts, sent_at, expires_at. Восьмизначный код отсутствует в БД/логах/очереди/API.
  TTL 10 минут, 5 попыток, повтор через 60 секунд; почасовые лимиты user/IP/email в rate_limits.

Подтверждение и OAuth используют общий player_auth_locks FOR UPDATE: проверка уникальности,
назначение почты и выбор владельца не расходятся при конкуренции. Занятый адрес/subject
не переносится, конфликт не объединяет аккаунты и прогресс. JSON-колонок не добавлено.

Миграция `2026_10_16_000007_add_player_profiles.php` сохраняет users и user_state без изменений:

- `player_profiles`: PK/FK user_id, пользовательский display_name, provider_name, avatar_url,
  updated_at. Пустой override возвращает имя последнего провайдера. Ранее сохранённые
  OAuth-имена переносятся при миграции; аватар обновляется при следующем входе.
- `player_identities`: verified_email nullable + индекс, email_auto_link boolean. Только
  настоящий boolean-флаг подтверждения; email не передаётся в клиентские ответы.
- `player_auth_locks(id=1)`: блокировка решений OAuth, включая первое назначение email,
  не допускает двух одновременных созданий по одной почте. PK/FK/UNIQUE защищают subject
  и единственный профиль каждого провайдера у игрока. Конфликт не объединяет прогресс.

Автопривязка требует однозначной подтверждённой почты с обеих сторон: Discord verified,
Google Gmail либо email_verified + совпадающий hd Workspace. Внешний Google email
требует явной привязки из аккаунта. Телефоны провайдеры в этих scopes не передают;
телефонные данные и неподтверждённые адреса не используются для связывания.

Миграция `2026_10_15_000006_add_player_oauth.php` добавляет только обычные колонки:

- `player_identities`: PK(provider, subject), unique(user_id, provider), FK user_id → users.id,
  display_name, created_at. Прежние аккаунты/ID/пароли и user_state не изменяются.
- `player_oauth_states`: token_hash PK, session_hash, provider, link_user_id, expires_at.
  Одноразовая привязка OAuth к текущей opaque-сессии; срок 10 минут, истёкшие записи
  очищаются при новом входе. Code, access token, client secret и JSON здесь не хранятся.

Production: MariaDB 11.8 на alwaysdata, `mysql-narnes.alwaysdata.net`, база `narnes_tarkov`.
Подключение Laravel `mysql`, InnoDB/utf8mb4_nopad_bin, TLS с проверкой CA.
Локальные и автономные тесты сохраняют SQLite. Доменный DDL MariaDB:
`database/migrations/sql/2026_10_15_domain.mysql.sql`; миграции 000001–000005 выбирают драйвер.
Составные/строковые PK сохраняются; их прежние SQLite rowid переносятся в отдельный
AUTO_INCREMENT UNIQUE rowid, который не показывается пользователю. Numeric id сохраняются
без перенумерации. display_name — TEXT, координаты — DOUBLE; user_state.value — LONGTEXT.
JSON-колонок контента нет. `wiki_write_locks(id=1)` сериализует проверку/сохранение графа.
Проверка/перенос каждой строки, согласованное переключение и резервные копии описаны
в [specs/mysql-migration.md](../../specs/mysql-migration.md).

`CONTENT_DIRECTORY=/home/narnes/tarkov-data/content` остаётся вне релиза.
`DB_ACCESS_PATH=/home/narnes/tarkov-data/mysql` — общий gate действующей MariaDB.
Старая SQLite `/home/narnes/tarkov-data/wiki.sqlite` и финальный согласованный снимок
сохраняются, маркер старого gate остаётся: старые PHP-процессы получают 503 вместо записи.
MySQL-копии: `/home/narnes/tarkov-data/mysql-backups`, SQL gzip + приватный манифест.
Приведённые ниже SQLite VACUUM/Backup API относятся к локальной совместимости и старому источнику.

Миграция `2026_10_15_000002_create_content_management.php` добавляет:

- `admin_users`: integer id, name, unique email, password, permissions JSON,
  email_verified_at, remember_token, timestamps; изолирована от `users` игроков.
- `roles` / `role_users`: Orchid permissions, FK исключительно к `admin_users`/`roles`.
- `admin_sessions`: штатная структура Laravel database sessions; cookie только `/admin`.
  Драйвер и таблица выбираются до создания контроллера, чтобы обычный вход сохранялся
  в том же хранилище, которое читает следующий запрос.
- `content_datasets`: unique path, payload — строковая ссылка `@file:SHA256`, timestamps.
- `quests`: integer id, quest_id, mode (pve/regular), name, trader_id, published,
  payload — строковая ссылка `@file:SHA256` и timestamps; UNIQUE(quest_id,mode), индекс(mode,published).

Миграция `2026_10_15_000003_create_content_references.php` добавляет производный
`content_references`: integer id PK, kind, variant DEFAULT default, value (прежний игровой ID),
label, search_text, source_dataset_id FK → content_datasets ON DELETE CASCADE nullable,
source_pointer, quest_record_id FK → quests ON DELETE CASCADE nullable, active DEFAULT true.
Остальные колонки NOT NULL; UNIQUE(kind,variant,value), INDEX(kind,variant,active).
JSON здесь отсутствует. Числовой PK используется Orchid, value никогда не вводится вручную.
Варианты разделяют игровые ID/slug локации, PVE/PVP квестов и этажи отдельных карт.
label/search_text сознательно дублируют имена из файлов для быстрого поиска без распаковки
каталога при каждом запросе. Поиск instr по нормализованному Unicode ограничен kind/variant;
это не полнотекстовый индекс. При текущих размерах достаточно нескольких тысяч имён на тип.
Повторная индексация upsert сохраняет PK; прежние отсутствующие ссылки помечаются inactive,
остаются читаемыми в своих формах и недоступны для назначения новым связям.
Реализованные предметные связи и запросы приведены в [content-relations.md](content-relations.md).

Миграция `2026_10_15_000004_create_wiki_domain.php` применяет
`database/migrations/sql/2026_10_15_domain.sql`: 45 предметных таблиц с PK/FK/UNIQUE/CHECK
и индексами обеих сторон M:N. Дополнительно создаются `wiki_point_world_positions`
(мировые координаты без вымышленного пересчёта в проценты), `wiki_location_aliases`
(UNIQUE scheme/value), `wiki_sources` (FK на набор и конкретную предметную сущность,
UNIQUE dataset/pointer), `wiki_projection_states` (SHA применённого файла),
`wiki_import_issues` (текст неизвестных ссылок). Предметные таблицы имеют revision,
archived, display_name и Unicode search_text. Имена поиска — производные колонки,
само содержимое связей — обычные FK и атрибуты; JSON-колонок нет.

Ключ — PK/FK item_id → wiki_items; выход — PK/FK point_id → wiki_map_points.
Квест хранит UNIQUE(external_id,mode,version_id) и UNIQUE legacy_quest_id → quests.
Цель хранит прежний external_id; UNIQUE(quest_id,external_id). Точка разделяет режимы
UNIQUE(external_id,mode), общий документный режим — all. Составные FK гарантируют
совпадение локации карты/точки и принадлежность этажа изображению. Родитель группы
связан составным FK с тем же владельцем. Laravel дополнительно запрещает циклы,
смешение режимов/версий и устаревшие revision. Идентификаторы и source_path скрыты
в формах; Relation принимает PK выбранного имени, сервер определяет настоящий FK.
Для строк с составным PK редактор использует внутренний SQLite rowid, не выводя его
в текстовое поле. Удаление заменено архивом; публикация обновляет только выбранный
раздел файла, сохраняя неизвестные поля и прежние ID прогресса.

Контент находится в `content/<первые 2 символа SHA>/<SHA>.json.gz` рядом с SQLite,
на alwaysdata — `/home/narnes/tarkov-data/content`, вне релизов и web root.
JSON квестов и справочников в БД не хранится. Файлы неизменяемы, проверяются по SHA-256;
смена ссылки в БД атомарна и отклоняет устаревшую форму. Все прежние версии остаются,
поэтому SQLite-снимок возвращает прежние тексты сменой ссылок. Проверка/restore бэкапа
проверяют наличие и контрольные суммы файлов. Для переноса на другой сервер нужны
SQLite-снимок **и весь каталог content**. Автоматического удаления старых версий пока нет.
Сессии, права Orchid и личный прогресс используют прежние форматы и контракты.

ID квестов/целей импортируются без замены; создание редакторской схемы не меняет
аккаунты/сессии/прогресс игроков. Импорт firstOrCreate сохраняет правки и черновики.
Снятые с публикации задания остаются в БД, публичный API их не выдаёт.
Rollback запрещён, резервные копии охватывают контент вместе с аккаунтами.
Restore очищает также admin_sessions и remember_token редакторов.

Владелец схемы: database/migrations/2026_10_15_000001_preserve_wiki_storage.php.
Laravel connection: config/database.php, SQLite DB_DATABASE либо APP_STORAGE/wiki.sqlite.
alwaysdata: /home/narnes/tarkov-data/wiki.sqlite вне /home/narnes/tarkov-api/public
и каталога релиза; SQLite >= 3.35, WAL/busy_timeout. deploy создаёт VACUUM INTO backup
существующей БД перед migrate; APP_KEY и файл БД сохраняются при повторной загрузке.
Actions main публикует код через tarkov-releases; .env/storage находятся в tarkov-shared.
Автоматический деплой требует существующую SQLite вне всех каталогов кода;
ошибка HTTP-проверки возвращает предыдущий код, не откатывая БД и записи пользователей.
wiki:backup / config/backup.php: до 7 daily и 3 deploy снимков, суммарно до 100 МиБ.
После предметного backfill локальная БД с исходным полным каталогом занимает около
88 МиБ: бюджет 100 МиБ допускает одну полную копию, более старые удаляются после
проверки новой. Это верхние пределы количества, а не обещание хранить десять копий.
Общий файловый lock, VACUUM INTO + quick_check, 0600; старые копии удаляются только после
исправного нового снимка, ссылки/живая SQLite/WAL/SHM/секреты не затрагиваются.
Ручное wiki:restore: выбранное имя + SHA-256, совпадающие схема/журнал миграций,
снимок текущей БД в группе deploy и атомарный SQLite Backup API в существующий файл.
API-запросы удерживают shared access.lock; restore ждёт exclusive, затем создаёт .restoring.
При успешном восстановлении sessions очищаются (повторный вход); users/user_state из снимка.
При ошибке проверки safety-снимок возвращает исходные данные; при обрыве маркер закрывает API.
Проверка восстановления: одноразовая $HOME/tarkov-restore-test-<random>/fixture.sqlite,
на той же FS alwaysdata, с искусственными users/sessions/user_state и реальной миграцией.
Единственное подключение задаётся в памяти отдельного Laravel-контейнера; production .env,
config cache, storage и SQLite не открываются. После теста удаляются только его собственные
плоские файлы/каталог, без рекурсивного обхода. Тестовая БД не загружается в GitHub artifacts.
users: string id PK, username nullable UNIQUE, password_hash nullable, created_at.
sessions: token_hash PK (SHA-256), user_id FK users.id, expires_at INDEX.
user_state: PK(user_id,state_key), user_id FK users.id, JSON value, version, updated_at.
rate_limits: bucket PK, count, expires_at. migrations: штатный журнал Laravel.
Ранее существующие таблицы принимаются без очистки; копировать БД перед migrate.
Rollback схемы намеренно запрещён, чтобы не удалять пользовательский прогресс.
Прямые prepared SQL сохранены только в проверенных src-сервисах CAS/сессий;
они выполняются через PDO соединения Laravel. Новые schema изменения — migrations.
Публичный файловый Cache и скомпилированные Blade хранятся в storage/framework,
вне web root. Ключи/БД/личные данные никогда не экспортируются в dist.
