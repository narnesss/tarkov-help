# Карта БД Tarkov Wiki

Локальные копии изображений из `config/public-media.php` применяются при выдаче публичного
JSON через PublicMedia. Схема SQL, ссылки ContentFiles, payload, ID и отношения не меняются.

Пакет 2026101603 добавляет только layeredFile в файловый maps.json. Строки точек, координаты,
слои, все PK/FK и личный прогресс не меняются. SQL сохраняет прежнюю систему процентов,
а document-map переводит её в координаты единой схемы при отображении выбранного уровня.

Обновление атласа документов 2026101602 меняет файлы maps/spawns/source через ContentUpdates.
348 совпавших точек сохраняют прежние внешние и SQL ID; 28 исходных записей архивируются,
7 новых получают отдельные ID. wiki_point_positions сохраняет существующую строку позиции
с order_index=0; координаты и layer_id отражают новую схему. Дополнительные старые позиции
остаются в архиве. Связи квестов, предметов и ключей не перенумеровываются. Происхождение
и исходные SVG-координаты записаны в JSON-файлах, новые колонки БД не нужны.

Пакеты ContentUpdates используют существующую wiki_projection_states:
owner_type=content-update, owner_id=номер пакета, content_ref=SHA-256.
Маркер записывается в той же транзакции, что SQL-ссылки на неизменяемые JSON-файлы.
Новые JSON-колонки не добавляются. Архив ошибочной точки сохраняет её SQL/external ID,
FK и личные отметки. Неподтверждённые внешние координаты остаются в файле с reviewReason
и sourceStatus=unverified-coordinates; API их не публикует до редакторского исправления.

Служебные ID точек (`loot-7`, `spawn-14`) локальны для карты. Прежняя SQL-строка и её FK
сохраняются; совпавшая точка другой локации получает новую SQL-строку с external_id
scoped-SHA256(kind, mode, location_id, public_id). wiki_sources связывает каждый указатель
с правильной строкой. ID в JSON и ключах личного прогресса не меняются. Одноразовый проход
repairPointScopes разделяет старые совпадения без перезаписи уже исправленных строк.
Проход фиксирует до 100 исходных точек за транзакцию WikiSync и возобновляется после сбоя.
Он выполняется до общей транзакции пакета контента и не меняет файлы, аккаунты или прогресс.

Миграция `2026_10_16_000009_add_player_password_recovery.php` добавляет `player_password_resets`:
PK/FK user_id, unique challenge_id, email/email_verified_at, session_hash, credential_hash,
code_hash HMAC-SHA256, attempts, sent_at, expires_at. TTL 1 час, 5 попыток, одноразовый код.
Запрос доступен гостю с прежним CSRF/Origin; контакт должен быть подтверждён в player_emails.
Лимиты в rate_limits: повтор через 60 секунд, 3 запроса/час на адрес, 10 на IP; ключи хешированы
и одинаковы для известных и неизвестных адресов. Никаких JSON или открытых паролей/кодов.
При восстановлении прежние users.id/username/created_at, player_emails/profiles/identities
и все user_state остаются неизменными. Меняется только password_hash; sessions и незавершённые
запросы владельца отзываются в той же транзакции. Автоматического входа после сброса нет.

Миграция `2026_10_16_000008_add_player_email_verification.php` добавляет:

- `player_emails`: PK/FK user_id, unique email, verified_at. Один подтверждённый контакт
  на прежний ID; смена только после кода. До подтверждения новый адрес не заменяет старый.
- `player_email_challenges`: PK/FK user_id, unique challenge_id, email, code_hash HMAC-SHA256,
  attempts, sent_at, expires_at. Восьмизначный код отсутствует в БД/логах/очереди/API.
  TTL 30 минут, 5 попыток, повтор через 60 секунд; почасовые лимиты user/IP/email в rate_limits.

Подтверждение и OAuth используют общий player_auth_locks FOR UPDATE: проверка уникальности,
назначение почты и выбор владельца не расходятся при конкуренции. Занятый адрес/subject
не переносится, конфликт не объединяет аккаунты и прогресс. JSON-колонок не добавлено.

Миграция `2026_10_16_000007_add_player_profiles.php` сохраняет users и user_state без изменений:

- `player_profiles`: PK/FK user_id, пользовательский display_name, provider_name, avatar_url,
  updated_at. Имя и аватар постоянны; OAuth заполняет их только для нового аккаунта. Ранее сохранённые
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

Миграция `2026_10_16_000010_add_player_password_history.php`: `player_password_history`
(id, user_id FK users ON DELETE CASCADE, password_hash, created_at, индекс user_id).
При успешной смене сохраняется только прежний хеш. Текущий и все накопленные хеши
проверяются password_verify; старые пароли до внедрения истории восстановить невозможно.
Миграция не меняет users и не требует переустановки паролей; JSON/открытые пароли не хранятся.

Миграция `2026_10_16_000011_stabilize_player_profiles.php` закрепляет текущие видимые имена
  в player_profiles.display_name, сохраняя аватары и все ID/связи/прогресс. Для старых
  ручных аккаунтов без профиля используется логин. Первоначальный администратор получает
  wiki.users; остальные редакторы не получают это право автоматически. Новых JSON-колонок нет.
  Orchid редактирует users/player_profiles/player_emails; смена пароля использует историю хешей.
  Удаление игрока в транзакции очищает sessions, user_state, identities, профили, контакты,
  OAuth-запросы, коды и историю только выбранного user_id. CAS не позволяет затереть новую версию.

Валидация AdminInput применяется к новым правкам админки до SQL/публикации файлов.
Схема, существующие строки и указатели файлов при установке этих проверок не изменяются.

Вкладки админки не создают новых таблиц и не переклассифицируют сохранённые строки.
PvE/PvP фильтруются по mode; назначение точек — по source_kind и связям документов.
Категории предметов выводятся из файлового каталога без изменения wiki_keys/item_id.
Справочники достижений, крафтов и убежища открывают прежний ContentDataset и нужный раздел.
Кэш публичного контента не меняет схему и не содержит таблицы аккаунтов или прогресса.
Версия сжатого слота проверяется по текущим SQL-ссылкам на неизменяемые файлы и опубликованным
квестам. После восстановления или смены публикации используется только соответствующий снимок.
Объединённые публичные наборы используют тот же файловый контент и сверяют ссылки всех входящих
наборов одним SELECT; первичные данные и схема для ускорения не меняются.

Миграция 2026_10_16_000012_add_player_legal_consents добавляет player_legal_consents
(user_id, document, version, accepted_at, method) и nullable player_oauth_states.legal_version.
Исторические аккаунты и согласия не переписываются. Новые подтверждения содержат только строки
и время, без JSON. FK удаляет подтверждения вместе с удаляемым пользователем.
Подтверждения создаются только при регистрации. Вход, автоматическая привязка по подтверждённой
почте и подключение провайдера в кабинете не требуют новых отметок и не создают фиктивных согласий.

Облегчённые публичные представления и revision не меняют схему SQL или файловый контент.
Версия учитывает SQL-ссылки, опубликованные квесты, режим, представление и формат кода.
Браузерная kord-public-content-v1 содержит только опубликованные справочные JSON;
учётные записи и user_state в неё не попадают. Проверка версии не создаёт сессию.

Миграция `000013_add_verified_quest_guides` обновляет только файловые ссылки дополнительных материалов quests/content_datasets; схема БД, ID, цели, награды, связи и user_state не изменяются. Схематические метки и источники остаются в quest-map-index; игровые XYZ не выводятся из пикселей иллюстраций.

Русские иллюстрации находятся в `public/assets/quest-media-ru`, соответствия оригиналов —
в файловом `guide/media-localization.json`. Подстановка выполняется и для полных карточек
CMS; она не меняет SQL-связи, ID целей, учётные записи или сохранённый прогресс.

Дополнение предметов через ContentUpdates сохраняет файловые версии последовательно;
ReferenceIndexer::appendItems и WikiImporter::appendItems обновляют только новые ссылки.
Полные каталоги не удерживаются вместе в памяти, существующие строки и указатели не переписываются.

Выборочные ответы PageContent и PublicJson читают существующие quests/content_datasets
и связи wiki_* без изменения схемы, файловых версий, ID, аккаунтов и user_state.
Для прежнего интерфейса полные справочники остаются доступны; личные данные в ответах отсутствуют.

Изоляция клиентских страниц не меняет SQL и ID. Частичная карточка помечена partial=true;
при сохранении журнала неизвестные этой карточке записи прогресса сохраняются без очистки.

Миграция 000014 добавляет nullable phone/country/city/gender/age в player_profiles
и пустую player_activity с FK user_id → users.id и каскадным удалением. Прежние столбцы,
ID, контакты, привязки, пароли и user_state не переписываются; JSON анкеты в SQL не хранится.
Анкета не добавляется в session.profile и общий кэш. До первого обращения после установки
исторического last_active_at нет. Телефон не подтверждён и не используется для идентификации.

- «Важные предметы» читает опубликованные quests выбранного режима и файловые версии items/reference из content_datasets. Публичный кэш содержит только требования; смена ссылок на файлы, названия или статуса публикации обновляет его автоматически. JSON остаётся в ContentFiles вне релиза. Личные отметки добавляются браузером из текущего профиля.
- /api/content/time не обращается к БД и не создаёт сессию: это серверная опора двух игровых часов.

### Оптимизация загрузки 2026-10-18

Схема, ID и связи не менялись. Условное чтение публичного контента сравнивает прежние SQL-ссылки файлов; готовые версии сохраняются в Laravel Cache до суток. Опциональный `state` в ответе сессии использует прежний `StateRepository::all` только для владельца текущей opaque cookie, с теми же value/version; новые поля БД не добавляются.
