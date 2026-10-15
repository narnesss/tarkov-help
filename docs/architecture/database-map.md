# Карта БД Tarkov Wiki

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
План расширения связей карт, ограничения и запросы приведены в [content-relations.md](content-relations.md).

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
