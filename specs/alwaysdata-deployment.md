# Laravel API на alwaysdata, сайт на Cloudflare

Читайте вместе со specs/cloudflare-deployment.md перед изменениями API и публикацией.

## Действующий production: MariaDB

Это рабочая ветка инструкции. SQLite ниже помечен как история или отдельный тест совместимости.
Команды wiki:backup/wiki:restore выбирают текущий драйвер; старые имена классов и workflows
со словом SQLite не означают, что production продолжает использовать SQLite.

Для действующего приложения по SSH (без смены .env и без вывода секретов):

```sh
cd /home/narnes/tarkov-api
php artisan wiki:backup --kind=daily
php artisan wiki:restore --list
```

Проверка выбранной SQL-копии, не восстановление (подставить точные name и SHA из list):

```sh
php artisan wiki:restore --check --backup=mysql-daily-YYYYMMDD-HHMMSS-xxxxxx.sql.gz --sha256=SHA256_ИЗ_LIST
```

Настоящий restore требует отдельного решения владельца; только после safety-копии,
сверки совместимой схемы и полного content, добавляется --confirm=RESTORE вместо --check.
Не выполнять restore ради проверки. [Полный контракт восстановления](recovery-contract.md)
отделяет проверку существующих файлов от учения с импортом MariaDB и content.

SQL-копии: /home/narnes/tarkov-data/mysql-backups/mysql-{daily|deploy}-*.sql.gz
и .manifest.json; content — отдельный постоянный каталог. Проверка SHA/схемы и
доступности файлов не заменяет пробное восстановление.
Daily workflow называется «Daily SQLite backup on alwaysdata» исторически, но backup.sh
вызывает wiki:backup с действующим mysql. Расписание — 00:30 UTC (03:30 Europe/Moscow).
Пределы config/backup.php: до 7 daily/3 deploy/100 МиБ, включая MariaDB; переменные
SQLITE_BACKUP_* пока сохраняют старые имена. Копии на том же аккаунте не защищают от его потери.
Внешняя доставка и проектные RPO/RTO описаны отдельно и не объявляются уже настроенными.

## Адреса и границы

Личный кабинет `/account`, Google/Discord и настройки OAuth описаны в [player-account.md](player-account.md).

- Публичный сайт: https://tarkov-compass.narnesss.workers.dev.
- PHP origin: https://narnes.alwaysdata.net; карточка https://admin.alwaysdata.com/site/1086865/.
- Worker `tarkov-compass` обслуживает dist через ASSETS и пересылает только /api и /api/*.
  При cookie eft_session HTML-шапка заменяется фрагментом PHP из /api/player-navigation
  до отправки браузеру. Контроллер только читает действующую сессию, не создаёт гостя;
  персонализированные страницы имеют private, no-store и Vary: Cookie.
- Orchid доступен на PHP origin `/admin` с отдельным входом редакторов. Динамические данные
  выдаются в `/api/content/*`; новые URL квестов получают оболочку через Worker.
  Заполнение и первый вход: [docs/orchid-content.md](../docs/orchid-content.md).
- Контент Orchid находится в `/home/narnes/tarkov-data/content` в неизменяемых JSON gzip-файлах.
  SQL хранит ссылки на версии. Для внешней копии нужны SQL gzip-снимок и весь content.
  Проверка/restore проверяют достижимость файлов; согласованный комплект и учение
  восстановления описаны в [recovery-contract.md](recovery-contract.md).
- Laravel 13 исполняется на alwaysdata PHP 8.4; production — MariaDB 11.8 через pdo_mysql.
- База `narnes_tarkov`, host `mysql-narnes.alwaysdata.net`, пользователь `narnes`.
  DB_CONNECTION=mysql, utf8mb4_nopad_bin, TLS с проверкой CA; пароль только в shared .env.
- Web root: /home/narnes/tarkov-api/public. Прежняя SQLite `/home/narnes/tarkov-data/wiki.sqlite`
  и финальный снимок сохраняются для аудита. Её `.restoring` остаётся после переноса:
  старый PHP или случайный откат настроек не должен записать данные в устаревший источник.
- Перенос, защита переключения и MySQL backup/restore: [mysql-migration.md](mysql-migration.md).
- API_ONLY=true отключает публичные Laravel-страницы. /up проверяет Laravel без сессии.
- API_PROXY_REQUIRED=true: каждый запрос API проверяет X-Wiki-Proxy-Token.
- APP_URL — origin сайта Cloudflare, а не alwaysdata: иначе CSRF отклонит сохранения.
- Cookie остаётся host-only, HttpOnly, Secure, SameSite=Lax; браузер обращается к своему /api/*.
- Worker заменяет proxy-заголовки, передаёт Origin/CSRF/cookie и проверенный CF-Connecting-IP.
  Laravel доверяет X-Wiki-Client-IP только при верном секрете; общий IP прокси не объединяет лимиты входа.
- Не включать CORS *, общий кэш API, trustProxies('*') и браузерный вызов alwaysdata напрямую.
- Секреты задаются вручную в .env/Cloudflare Secret, не в Git и не в браузерном модуле.

## 1. Настройки панели alwaysdata

В Environment → PHP выберите 8.4 для CLI и сайта. В Remote access → SSH включите
пользователя; его точное имя и host сверяйте с панелью (обычно narnes@ssh-narnes.alwaysdata.net).
В карточке сайта 1086865:

- адрес narnes.alwaysdata.net;
- тип PHP;
- root directory /home/narnes/tarkov-api/public;
- PHP 8.4, если версия задаётся отдельно от Environment;
- HTTPS с автоматическим сертификатом; HTTP cache для этого API выключить.

Сначала загрузите файлы: изменение root directory само не создаёт каталог.
Production-база MariaDB создаётся в MySQL-разделе панели. SQLite остаётся локальным вариантом.
Не ставьте Laravel заново из Marketplace: используется наш composer.lock и код API.

## 2. История: первоначальная загрузка API с SQLite

Не применять этот сценарий к действующему mysql production и не возвращать его .env
к SQLite. Он сохранён для понимания переноса/локальной совместимости; первоначальный
переход на MariaDB — [mysql-migration.md](mysql-migration.md).

На компьютере в корне репозитория:

```powershell
npm run package:alwaysdata
scp build/alwaysdata-api.tar.gz narnes@ssh-narnes.alwaysdata.net:
ssh narnes@ssh-narnes.alwaysdata.net
```

Архив содержит исходники API без vendor, dist, изображений, .env и БД.
На сервере:

```sh
umask 077
mkdir -p /home/narnes/tarkov-api
tar -xzf /home/narnes/alwaysdata-api.tar.gz -C /home/narnes/tarkov-api
cd /home/narnes/tarkov-api
test -f .env || cp deployment/alwaysdata/environment.example .env
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
nano .env
```

Скопируйте сгенерированные 64 символа в API_PROXY_TOKEN=. APP_KEY пока оставьте пустым:
deploy.sh создаст его при первом запуске. Остальные параметры примера уже содержат
ваши адреса. При обновлении сохраняйте существующие .env и APP_KEY.

```sh
sh deployment/alwaysdata/deploy.sh
```

Скрипт проверит версии PHP/SQLite и расширения, установит vendor без dev-зависимостей,
создаст закрытый каталог БД, сделает согласованную VACUUM INTO копию существующей БД,
выполнит migrate --force и optimize. Никогда не использовать migrate:fresh, reset или
touch с перезаписью БД. DB_DATABASE находится вне каталога релиза; замена кода не удаляет прогресс.
Резервные копии wiki.sqlite.backup-* хранятся рядом с БД. Единая политика ниже ограничивает
копии перед деплоем и ежедневные снимки. Обычный cp работающей WAL-БД не заменяет VACUUM INTO.

Проверки origin после настройки web root:

```sh
curl --fail https://narnes.alwaysdata.net/up
curl -i https://narnes.alwaysdata.net/api/session
```

Первая возвращает 200, вторая 403 JSON без секрета: это ожидаемая защита origin.
При 500 смотрите storage/logs/laravel.log по SSH. Не включайте APP_DEBUG публично.

## 3. Автоматические обновления из main

`.github/workflows/check.yml` после успешных jobs `check` и `mysql` скачивает API-артефакт именно
этого запуска и выполняет `deployment/alwaysdata/github-deploy.sh`. Только push в main
получает SSH-секреты; pull_request проверяет код без публикации. Cloudflare продолжает
собственную Git-сборку сайта. Два деплоя API не идут параллельно: GitHub concurrency без
отмены текущего процесса и серверный flock. Проверка может обогнать следующий commit,
поэтому миграции и API-контракты должны оставаться совместимыми с предыдущим релизом.

Однократно создайте отдельный SSH Ed25519 ключ без passphrase для Actions. Закрытый ключ
не передавайте в чат и не коммитьте. Открытый ключ добавьте пользователю narnes в alwaysdata
(Remote access → SSH/SFTP → редактирование пользователя → SSH keys). Он даёт runner доступ
к файлам этого SSH-пользователя; ограничьте круг людей, имеющих право менять main/workflow.
По возможности добавляйте ключ с `restrict` в authorized_keys, без forwarding и PTY.

В GitHub → tarkov-help → Settings → Secrets and variables → Actions добавьте Repository secrets:

- `ALWAYSDATA_SSH_KEY`: полный закрытый OpenSSH ключ с BEGIN/END и настоящими переводами строк.
- `ALWAYSDATA_KNOWN_HOSTS`: строки known_hosts для ssh-narnes.alwaysdata.net.
  Сверьте fingerprint host key с опубликованным alwaysdata ключом или уже проверенным
  SSH-подключением. Один ssh-keyscan без независимой сверки не устанавливает доверие.

Host `ssh-narnes.alwaysdata.net` и user `narnes` заданы в github-deploy.sh. Если панель
показывает другие значения, исправьте скрипт и known_hosts вместе. StrictHostKeyChecking
никогда не отключать. Ключ хранится в закрытом временном каталоге runner и удаляется
после job. `.env`, APP_KEY и proxy-токен в GitHub для деплоя не копируются.

Серверный `release.sh` автоматически создаёт структуру:

- `/home/narnes/tarkov-api` — ссылка на активный релиз; web root остаётся прежним;
- `/home/narnes/tarkov-releases/<SHA>-<run>-<attempt>` — исходники, vendor и bootstrap/cache;
- `/home/narnes/tarkov-shared/.env` и `storage` — постоянные приватные файлы;
- MariaDB narnes_tarkov — действующая БД; content и mysql-backups — в /home/narnes/tarkov-data;
- `/home/narnes/tarkov-data/wiki.sqlite` — прежний источник переноса, закрытый для записи;
- `/home/narnes/tarkov-api-before-releases` — сохранённый первоначальный каталог приложения.

Первый запуск копирует существующие .env/storage в shared; все последующие релизы используют
ссылки на них. Редактировать настройки после перехода можно через `/home/narnes/tarkov-api/.env`
(это ссылка на shared); применять изменения через штатный релиз и обновление PHP/config cache
по процедуре, не запускать старую SQLite-инициализацию и не менять APP_KEY.
Нельзя снова распаковывать архив поверх активного каталога: используйте Actions.

Новый релиз устанавливает зависимости из composer.lock и проверяет production-конфигурацию.
При совпадении composer.json и composer.lock vendor копируется из действующего релиза
обычным cp -a, без жёстких ссылок. Composer всё равно проверяет зависимости и пересоздаёт
autoload для нового кода. Это исключает повторную распаковку тысяч файлов Orchid на
ограниченном alwaysdata CPU; распаковка идёт одним процессом, его таймаут — 900 секунд.
Перед migrate/optimize создаётся согласованный SQL backup MariaDB; для локальной SQLite — VACUUM INTO.
Только после этого меняется ссылка. На первом переходе каталог API переименовывается
в before-releases; возможен краткий перерыв. Проверка HTTPS `/up` обязательна: при ошибке
возвращается предыдущий код и job красный. БД не откатывается, изменения схемы должны
быть обратно совместимыми; запрещены migrate:fresh/reset и удаление пользовательских данных.

До создания временного файла API-ключа и загрузки архива runner под тем же серверным flock удаляет незавершённые каталоги кода
без подтверждённой публикации. `REVISION` создаётся до установки и сам по себе не означает
успех: новый релиз получает `.publication-pending`, который переименовывается в `PUBLISHED`
только после restart и совпадения `/up`. Для старых релизов без этих маркеров требуется
корректный REVISION и готовый `bootstrap/cache/config.php` после optimize.
Активный каталог всегда защищён. Вместе с удалённым кодом очищается только одноимённый
обычный архив в настоящем `tarkov-incoming`; ссылки и посторонние файлы сохраняются. Это освобождает квоту после
неудачных установок до копирования нового vendor; shared, tarkov-data и копии не обходятся.
Сохраняются три последних завершённых каталога кода и предыдущий активный релиз; очистка не следует
по ссылкам .env/storage. Первоначальный каталог не удаляется автоматически; SQL-копии
очищаются политикой соответствующего драйвера после создания исправного снимка.
Следите за квотой alwaysdata и скачивайте важные копии. После подтверждения первого
автоматического деплоя старый before-releases можно удалить вручную, убедившись, что БД
и секреты находятся в постоянных каталогах. Не удаляйте shared или tarkov-data.

Статус: GitHub → Actions → Check Laravel and Cloudflare export → Deploy Laravel API to alwaysdata.
На сервере `cat /home/narnes/tarkov-api/REVISION` показывает опубликованный SHA/run/attempt.
Повторный запуск failed jobs получает новый attempt и может повторить деплой. Если нет
секретов или SSH недоступен, job явно падает; старый API продолжает работать.

## 4. Исторические детали SQLite backup и совместимость

Ниже формат SQLite-снимков, VACUUM и WAL относятся только к локальной/исторической SQLite.
Для production MariaDB использовать раздел «Действующий production» выше и recovery-contract.md.
Названия workflow/переменных сохранены ради совместимости; команды выбирают текущий драйвер.
Не переносить SQLite-пути, quick_check и WAL-процедуры на MariaDB.

Команда `php artisan wiki:backup --kind=daily` создаёт согласованный VACUUM INTO снимок
даже при активном WAL, проверяет `PRAGMA quick_check` и только затем публикует файл.
Файлы приватные (0600), находятся рядом с SQLite вне web root и не содержат .env.
Обычный shell cp работающей БД использовать нельзя.

Расписание настроено в `.github/workflows/backup-alwaysdata.yml`: каждый день в 03:30
Europe/Moscow (00:30 UTC) GitHub подключается по SSH и запускает бэкап на alwaysdata.
Используются существующие Secrets ALWAYSDATA_SSH_KEY/ALWAYSDATA_KNOWN_HOSTS; БД не
скачивается на runner и не публикуется как GitHub artifact. Новые ключи не нужны.
Общая concurrency group с деплоем исключает их одновременный запуск, файловый lock
защищает также ручные запуски. Работает только main, не pull_request/произвольные ветки.

После первого деплоя запустите Actions → Daily SQLite backup on alwaysdata → Run workflow
→ main. В логе видны путь снимка, число/объём копий и удалённое количество. Повторный
Run workflow за UTC-день проверит существующий снимок и не создаст лишний файл.
Расписание не зависит от компьютера пользователя. GitHub может задержать запуск;
у публичного репозитория оно отключается после 60 дней без активности — проверяйте
Actions и при необходимости Enable workflow. Сам бэкап занимает секунды и не собирает сайт.

Если позднее переходите на серверное расписание alwaysdata, сначала отключите GitHub
Daily SQLite backup workflow, затем Advanced → Scheduled tasks → Command:

- команда: `sh /home/narnes/tarkov-api/deployment/alwaysdata/backup.sh`;
- SSH user: narnes; Environment PHP: 8.4;
- ежедневно, сверяйте timezone в панели: UTC-расписание GitHub нельзя переносить как местное время сервера;
- не добавляйте второе задание с той же командой; ошибки доступны в Logs задания.

Путь через tarkov-api переживает смену релиза. Скрипт сам переходит в корень активного
приложения; shell PATH/CLI PHP берутся из Environment аккаунта. Копирование и очистка
в любом варианте исполняются на alwaysdata. Проверить вручную по SSH:

```sh
sh /home/narnes/tarkov-api/deployment/alwaysdata/backup.sh
du -sh /home/narnes/tarkov-data
```

Настройки в config/backup.php имеют безопасные defaults, поэтому существующий .env не
нужно менять. Для переопределения добавьте в shared .env и обновите Laravel config cache:

- `SQLITE_BACKUP_DAILY_KEEP=7` — до семи последних ежедневных копий, одна на UTC-день;
- `SQLITE_BACKUP_DEPLOY_KEEP=3` — до трёх снимков перед миграциями;
- `SQLITE_BACKUP_MAX_MIB=100` — общий предел объёма обеих групп (100 МиБ).

При достижении бюджета остаётся меньше копий: приоритет у свежих, последний проверенный
снимок сохраняется всегда. Повтор ежедневной команды за UTC-день использует уже исправную
копию и не создаёт дубликат. Deploy-снимки создаются каждый раз перед migrate: prepare.php
использует тот же сервис и файловый lock, что daily. Параллельный бэкап ждёт lock до минуты.

Очистка касается только обычных файлов с точным именем
`wiki.sqlite.backup-[daily-]YYYYMMDD-HHMMSS-xxxxxx` (xxxxxx — 6 hex). Старые deploy-копии
прежнего формата тоже входят в лимит. Ссылки, .env, живая БД, WAL/SHM, другой DB basename
и произвольные пользовательские файлы не удаляются. Наши .partial старше суток удаляются
только после исправного снимка. При ошибке создания/проверки старые копии сохраняются;
превышение бюджета самой БД блокирует новый бэкап и деплой с понятной ошибкой.

100 МиБ ограничивают копии этого SQLite, а не всю квоту аккаунта; на время VACUUM требуется
место ещё на один снимок. Важные копии скачивайте перед автоматической очисткой: эти
снимки находятся на том же сервере, поэтому для восстановления после потери аккаунта
нужна отдельная скачанная копия. Для восстановления остановите запись и выберите снимок,
прошедший quick_check; не заменяйте открытую WAL-БД простым cp.

## 5. Исторические детали восстановления SQLite

Файлы wiki.sqlite.backup-* и операции WAL/inode ниже — только SQLite.
Workflow restore-alwaysdata.yml и wiki:restore сейчас также поддерживают mysql-имена;
действующая production-процедура и требуемые файлы — в начале документа и recovery-contract.md.

Workflow **Manual SQLite restore on alwaysdata** (`restore-alwaysdata.yml`) запускается
только вручную через Actions → Run workflow → main. Push и ежедневное расписание
никогда не вызывают восстановление. Используются прежние SSH Secrets; содержимое БД
не загружается в GitHub. Новых прав/ключей не требуется. На alwaysdata нужно расширение
PHP sqlite3 (проверяется deploy.sh), помимо pdo_sqlite.

Порядок действий:

1. Выберите `action=list`, остальные поля оставьте пустыми. В логе job появятся JSON-строки
   с `name`, `bytes`, `sha256`, `valid`. Это только метаданные копий, не аккаунты и не прогресс.
2. Выберите исправный снимок (`valid=true`), скопируйте точное имя и SHA-256 из его строки.
   Запустите `action=check`, заполните `backup` и `sha256`. База не изменится; проверяются
   целостность SQLite, внешние ключи, схема и журнал миграций относительно текущего релиза.
3. Для применения запустите `action=restore` с теми же именем/SHA-256 и включите
   `confirm_restore`. Данные, записанные позже выбранного снимка, заменятся. Все сессии
   сбрасываются, чтобы старые отозванные cookie не ожили: после успеха пользователям нужен вход.

Скрипт принимает только имя из списка, без каталогов, ссылок и shell-выражений. Если copy
уже удалена ротацией, изменилась контрольная сумма или схема несовместима, запись не
начнётся. Старую схему автоматически не мигрируем: сначала подберите совместимый снимок
или выполняйте отдельно спланированное восстановление вместе с нужным релизом.

Deploy, daily и restore используют одну GitHub concurrency group и серверный deploy lock.
API-запросы и создание копий удерживают shared `.access.lock`, восстановление получает
exclusive и ждёт завершения старых запросов до минуты. Во время операции /api и /up
отвечают private JSON 503 с Retry-After. Worker/Cloudflare-переменные и .env не меняются.

Выбранный снимок сначала копируется в приватный временный файл: ротация не сможет
лишить операцию выбранных данных. Затем текущая БД, включая WAL, сохраняется в новую
проверенную `wiki.sqlite.backup-YYYYMMDD-HHMMSS-xxxxxx` (группа deploy). Восстановление
идёт штатным SQLite Backup API в тот же файл, без rm/cp живой БД, WAL или SHM. После
проверки SQLite/FK/схемы и Laravel снимается маркер `.restoring`, API снова принимает запросы.
Имя сохранённой текущей базы выводится в логе и остаётся доступным для обратного restore.
На неё распространяются прежние лимиты 3 deploy / 100 МиБ: важную копию скачайте заранее,
последующие бэкапы могут её удалить. Для подготовки нужно место ещё на выбранный снимок
и safety-копию; если сохранить текущую исправную БД не удалось, восстановление не начинается.

При ошибке проверки после записи сервис возвращает сохранённую текущую БД и завершает job
ошибкой. Если аварийный возврат тоже не удался или процесс был оборван, `.restoring`
остаётся, а API возвращает 503 даже после освобождения flock. Не удаляйте этот маркер
вслепую: изучите log и сохранённое в нём имя safety-копии. После остановки прежнего job
можно повторно запустить явный restore исправного совместимого снимка — он заново
проверит данные и сохранит текущую базу перед записью. Повреждённая текущая БД, которую
невозможно согласованно скопировать, требует отдельного восстановления по SSH с сохранением
её файлов; автоматический workflow в таком случае прекращается без перезаписи.

Проверки разработки выполняют реальный restore только на временной тестовой SQLite,
включая WAL, сохранение текущих строк, аварийный возврат, запрет записи API и отказ без
подтверждения. При приёмке на production запускайте только list/check; restore выбранных
живых данных требует отдельного решения владельца, а не проверки работоспособности workflow.

## 6. Проверка совместимости на отдельной SQLite alwaysdata

Этот автоматический тест остаётся действующим, но проверяет только одноразовую SQLite.
Он не подтверждает восстановление действующей MariaDB с её content; это отдельное учение.

После каждого успешного deploy job запускает **Verify restore on a disposable SQLite on
alwaysdata**. Тот же сценарий можно повторить через Actions → **Test SQLite restore on
alwaysdata** → Run workflow → main. Это отдельный workflow без полей выбора рабочей БД
или backup. Для него не используйте Manual SQLite restore с action=restore.

`test-restore.sh` берёт общий deploy lock и сверяет SHA активного релиза с SHA workflow.
`check-restore.php` создаёт новый приватный `$HOME/tarkov-restore-test-<random>` на файловой
системе alwaysdata; внутри только `fixture.sqlite`, её WAL/SHM, копии и блокировки.
Проверяются реальные команды BackupSqlite/RestoreSqlite и реальная миграция схемы.
Laravel-контейнер создаётся отдельно с единственным явно заданным fixture-подключением:
production bootstrap, .env, APP_KEY, config cache и storage не загружаются. Аргументы
с путями запрещены; рабочая `/home/narnes/tarkov-data/wiki.sqlite` не открывается.

Сценарий проверяет:

- daily-снимок и повтор без дубликата; list/check без изменения данных;
- отказ без подтверждения и с неверным SHA-256;
- shared/exclusive flock именно на серверной FS (важно для NFS);
- восстановление тестовых аккаунтов/прогресса и сохранение текущих строк из активного WAL;
- очистку старых сессий и обратное восстановление safety-копии;
- намеренный сбой проверки после записи и автоматический возврат исходного состояния;
- снятие маркера, целостность/FK, сохранение inode, 0600 и лимит до 4 копий / 4 МиБ.

В конце успеха или перехваченной ошибки удаляются только файлы собственного случайного
каталога, без рекурсии и следования по ссылкам. После обрыва процесса проверьте оставшиеся
`$HOME/tarkov-restore-test-*` вручную: автоочистка чужих/прежних каталогов не выполняется.
В Actions выводятся только PASS/FAIL и revision, SQLite не скачивается на runner.
Зелёный job и последняя строка `PASS: тестовые файлы удалены` подтверждают полный цикл.
Обычный CI также запускает этот сценарий без SSH и без доступа к production.

Для ручного запуска по SSH (SHA берётся из текущего REVISION):

```sh
revision=$(cat /home/narnes/tarkov-api/REVISION)
bash /home/narnes/tarkov-api/deployment/alwaysdata/test-restore.sh "${revision%%-*}"
```

Этот тест не проверяет содержимое живых аккаунтов и не восстанавливает production.
Проверка API-блокировки HTTP остаётся в Laravel feature-тестах CI на временной SQLite.

## 7. Подключение Cloudflare

После push дождитесь публикации Worker из main. Сборка npm run build,
Deploy command npx wrangler deploy. В Cloudflare → Workers & Pages → tarkov-compass →
Settings → Variables and Secrets добавьте:

- Secret API_PROXY_TOKEN: тот же секрет, что в alwaysdata .env.
- Plaintext variable API_ENABLED: строка true, только после успешного /up.

API_ORIGIN=https://narnes.alwaysdata.net задан в wrangler.jsonc. keep_vars=true сохраняет
dashboard-only API_ENABLED при последующих deploy. Не добавлять API_ENABLED в vars
репозитория: это бы перезаписывало ручное включение/выключение. Secrets Wrangler сохраняет.
Для отключения синхронизации установите API_ENABLED=false и примените настройки.
До настройки Worker возвращает serverProfiles=false: работают прежние локальные профили.

/profile-runtime.mjs получает private, no-store и содержит только boolean;
API_ONLY/API_PROXY_TOKEN/APP_KEY/DB никогда не попадают туда или в dist.
На /api/* upstream redirects/HTML/network errors становятся JSON 503. Set-Cookie,
JSON ошибки 401/403/409/422/429 и CAS версии проходят без общего кэша.
Origin fetch использует cache: 'no-store'; cf.cacheTtl=0 недопустим: он принудительно
кэширует даже private-ответ и может убрать сессионный Set-Cookie. Проверять сквозной вход.
run_worker_first включает API, runtime-модуль и `/quests/*` для новых динамических заданий; карты и остальные страницы
продолжают обслуживаться статикой. Pages-конфигурация остаётся автономной статикой.

## 8. Проверка после включения

Проверяйте стандартную перезагрузку без ручной очистки кэша. Если помогает только Ctrl+F5,
это дефект версионирования ресурсов; диагностика не считается успешной приёмкой.
Текущие ручные query-версии и требуемый fingerprint описаны в [client-api-contract.md](client-api-contract.md).
Откройте основной сайт: в Network /api/session и /api/state должны вернуть JSON;
в интерфейсе профиля должны появиться регистрация и вход. Создайте аккаунт,
отметьте цель, обновите страницу, выйдите и войдите снова; затем проверьте вход в
другом браузере. Старый локальный прогресс можно скачать до включения и импортировать
после входа в нужный аккаунт. Не считать /up или CI доказательством сквозной синхронизации.

## Проверки разработки

php artisan test; php tests/php/run.php; npm run build; npm test;
node scripts/check-export-freshness.mjs; npm run package:alwaysdata;
npx wrangler@4.151.0 deploy --dry-run; git diff --check. CI отдельно проверяет API_ONLY.
Содержимое архивов и .env не коммитить. Push main публикует сайт Cloudflare и запускает
SSH-деплой API после успешного CI; оба результата проверять отдельно. Production smoke
в CI проверяет повторные релизы, сохранение .env/storage и тестовых данных, HTTP-откат и очистку ссылок.
MariaDB проверяется job mysql; alwaysdata disposable SQLite restore — отдельная проверка совместимости.

Free alwaysdata предназначен для некоммерческого личного использования; website
остаётся на предоставленном alwaysdata.net адресе. При рекламе/монетизации пересмотреть тариф.
По правилам требуется периодически входить в панель: первые 12 месяцев — каждые 120 дней.

Источники:
- https://help.alwaysdata.com/en/docs/web-hosting/sites/add-a-site/
- https://help.alwaysdata.com/en/docs/web-hosting/languages/php/configuration/
- https://help.alwaysdata.com/en/docs/web-hosting/remote-access/ssh/
- https://help.alwaysdata.com/en/docs/web-hosting/tasks/
- https://www.sqlite.org/lang_vacuum.html
- https://www.sqlite.org/backup.html
- https://www.php.net/manual/en/sqlite3.backup.php
- https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/use-secrets
- https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#schedule
- https://help.alwaysdata.com/en/docs/admin-billing/profile/suspension/
- https://developers.cloudflare.com/workers/static-assets/routing/worker-script/
- https://developers.cloudflare.com/workers/wrangler/configuration/
- https://developers.cloudflare.com/workers/runtime-apis/request/

Оптимизация preload 2026-10-18: API-пакет содержит публичные MJS верхнего уровня и `public/vendor/*.mjs`, чтобы PageModules мог собрать те же ссылки для серверного HTML `/important-items`. Карты, изображения, dist и личные данные не добавляются. Упаковочная проверка сверяет SHA каждого исходника графа с релизом.
## Перезапуск PHP после переключения релиза

Actions требует Repository secret `ALWAYSDATA_API_KEY` — API-токен alwaysdata для аккаунта narnes. Создание токена в панели требует предварительно включённой владельцем 2FA: Profil → Authentification, затем Tokens. Ключ не передавать в чат и не добавлять в Git. Он даёт доступ к API управления аккаунтом; ограничивайте его права/IP там, где это допускает панель. Для публикации используется только сайт 1086865.

Runner передаёт ключ через stdin проверенного SSH-соединения и атомарно сохраняет его в `/home/narnes/tarkov-shared/alwaysdata-api-key` (600), вне web root, архива и `.env`. `restart-site.sh` проверяет доступ к сайту до миграций. После переключения симлинка он вызывает официальный `POST https://api.alwaysdata.com/v1/site/1086865/restart/`; ключ поступает curl через stdin и не появляется в аргументах процесса или логах. У запросов ограничены таймауты и число попыток; отказ авторизации не повторяется.

`REVISION` создаётся до optimize. Middleware `DeploymentRevision` добавляет к `/up` некэшируемый заголовок `X-Wiki-Release` из конфигурации именно исполняемого релиза. Деплой ждёт совпадения SHA/run/attempt: HTTP 200 от старого PHP не считается успехом. При ошибке перезапуска или проверки возвращается предыдущая ссылка и повторно запрашивается перезапуск PHP. Данные/схема автоматически не откатываются. Ошибка повторного перезапуска явно требует оператора; job остаётся красным. При отсутствии ключа публикация останавливается до миграций/переключения.

Перезапуск PHP-сайта alwaysdata также перезапускает другие Apache-сайты того же аккаунта, если они есть. Ручной Restart остаётся аварийным действием. Для отката механизма вернуть согласованный предыдущий код release/workflow, сохранив shared, `.env`, APP_KEY и БД. Удаление/отзыв ключа — отдельное действие владельца; обычный откат его не удаляет.

Официальные источники: [перезапуск сайта](https://help.alwaysdata.com/en/docs/web-hosting/sites/restart-a-site/), [пример API](https://help.alwaysdata.com/en/docs/development/api/examples/).
