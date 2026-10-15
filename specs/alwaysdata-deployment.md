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

## 2. Загрузка API

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

## 3. Подключение Cloudflare

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

## 4. Проверка после включения

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
Содержимое архивов и .env не коммитить. Изменения кода на alwaysdata нужно загружать
отдельно: git push публикует сайт Cloudflare, но не запускает SSH-деплой alwaysdata.

Free alwaysdata предназначен для некоммерческого личного использования; website
остаётся на предоставленном alwaysdata.net адресе. При рекламе/монетизации пересмотреть тариф.
По правилам требуется периодически входить в панель: первые 12 месяцев — каждые 120 дней.

Источники:
- https://help.alwaysdata.com/en/docs/web-hosting/sites/add-a-site/
- https://help.alwaysdata.com/en/docs/web-hosting/languages/php/configuration/
- https://help.alwaysdata.com/en/docs/web-hosting/remote-access/ssh/
- https://help.alwaysdata.com/en/docs/admin-billing/profile/suspension/
- https://developers.cloudflare.com/workers/static-assets/routing/worker-script/
- https://developers.cloudflare.com/workers/wrangler/configuration/
- https://developers.cloudflare.com/workers/runtime-apis/request/
