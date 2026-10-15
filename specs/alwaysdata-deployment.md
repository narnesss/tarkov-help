# Laravel API на alwaysdata, сайт на Cloudflare

Читайте вместе со specs/cloudflare-deployment.md перед изменениями API и публикацией.

## Адреса и границы

- Публичный сайт: https://tarkov-compass.narnesss.workers.dev.
- PHP origin: https://narnes.alwaysdata.net; карточка https://admin.alwaysdata.com/site/1086865/.
- Worker `tarkov-compass` обслуживает dist через ASSETS и пересылает только /api и /api/*.
- Laravel 13 исполняется на alwaysdata PHP 8.4; SQLite >= 3.35 (SQL RETURNING).
- Web root: /home/narnes/tarkov-api/public. SQLite: /home/narnes/tarkov-data/wiki.sqlite.
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
SQLite — файл, создавать базу MySQL/PostgreSQL в панели не требуется.
Не ставьте Laravel заново из Marketplace: используется наш composer.lock и код API.

## 2. Первоначальная загрузка API (уже выполнена)

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
Резервные копии wiki.sqlite.backup-* хранятся рядом с БД; скачивайте важные копии и следите
за квотой диска. Обычный cp работающей WAL-БД не заменяет согласованную копию.

Проверки origin после настройки web root:

```sh
curl --fail https://narnes.alwaysdata.net/up
curl -i https://narnes.alwaysdata.net/api/session
```

Первая возвращает 200, вторая 403 JSON без секрета: это ожидаемая защита origin.
При 500 смотрите storage/logs/laravel.log по SSH. Не включайте APP_DEBUG публично.

## 3. Автоматические обновления из main

`.github/workflows/check.yml` после успешного job `check` скачивает API-артефакт именно
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
- `/home/narnes/tarkov-data/wiki.sqlite` — существующая SQLite по неизменному DB_DATABASE;
- `/home/narnes/tarkov-api-before-releases` — сохранённый первоначальный каталог приложения.

Первый запуск копирует существующие .env/storage в shared; все последующие релизы используют
ссылки на них. Редактировать настройки после перехода можно через `/home/narnes/tarkov-api/.env`
(это ссылка на shared), затем запустить deploy.sh активного релиза для обновления кэша.
Нельзя снова распаковывать архив поверх активного каталога: используйте Actions.

Новый релиз устанавливает зависимости из composer.lock, проверяет production-конфигурацию
и наличие существующей SQLite, создаёт VACUUM INTO backup, выполняет migrate/optimize.
Только после этого меняется ссылка. На первом переходе каталог API переименовывается
в before-releases; возможен краткий перерыв. Проверка HTTPS `/up` обязательна: при ошибке
возвращается предыдущий код и job красный. БД не откатывается, изменения схемы должны
быть обратно совместимыми; запрещены migrate:fresh/reset и удаление пользовательских данных.

Сохраняются три последних каталога кода и предыдущий активный релиз; очистка не следует
по ссылкам .env/storage. Первоначальный каталог и backup SQLite не удаляются автоматически.
Следите за квотой alwaysdata и скачивайте важные копии. После подтверждения первого
автоматического деплоя старый before-releases можно удалить вручную, убедившись, что БД
и секреты находятся в постоянных каталогах. Не удаляйте shared или tarkov-data.

Статус: GitHub → Actions → Check Laravel and Cloudflare export → Deploy Laravel API to alwaysdata.
На сервере `cat /home/narnes/tarkov-api/REVISION` показывает опубликованный SHA/run/attempt.
Повторный запуск failed jobs получает новый attempt и может повторить деплой. Если нет
секретов или SSH недоступен, job явно падает; старый API продолжает работать.

## 4. Подключение Cloudflare

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
run_worker_first включает только API и runtime-модуль, поэтому карты и страницы
продолжают обслуживаться статикой. Pages-конфигурация остаётся автономной статикой.

## 5. Проверка после включения

Обновите браузер без кэша (Ctrl+F5: bootstrap.mjs может оставаться в кэше час).
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
в CI проверяет повторные релизы, сохранение .env/storage/SQLite, HTTP-откат и очистку ссылок.

Free alwaysdata предназначен для некоммерческого личного использования; website
остаётся на предоставленном alwaysdata.net адресе. При рекламе/монетизации пересмотреть тариф.
По правилам требуется периодически входить в панель: первые 12 месяцев — каждые 120 дней.

Источники:
- https://help.alwaysdata.com/en/docs/web-hosting/sites/add-a-site/
- https://help.alwaysdata.com/en/docs/web-hosting/languages/php/configuration/
- https://help.alwaysdata.com/en/docs/web-hosting/remote-access/ssh/
- https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/use-secrets
- https://help.alwaysdata.com/en/docs/admin-billing/profile/suspension/
- https://developers.cloudflare.com/workers/static-assets/routing/worker-script/
- https://developers.cloudflare.com/workers/wrangler/configuration/
- https://developers.cloudflare.com/workers/runtime-apis/request/
