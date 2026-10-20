#!/usr/bin/env bash
# Релиз из проверенного CI-архива; .env, storage и SQLite не заменяются.
set -Eeuo pipefail
umask 077
release_id=${1:?Нужен SHA-run-attempt}
base=${2:-$HOME}
health_url=${3:-https://narnes.alwaysdata.net/up}
[[ $release_id =~ ^[a-f0-9]{40}-[0-9]+-[0-9]+$ ]] || { echo 'Некорректный ID релиза' >&2; exit 1; }
[[ $base = /* && -d $base ]] || { echo 'Нужен существующий абсолютный каталог' >&2; exit 1; }
base=$(cd "$base" && pwd -P)
current=$base/tarkov-api
shared=$base/tarkov-shared
releases=$base/tarkov-releases
archive=$base/tarkov-incoming/$release_id.tar.gz
release=$releases/$release_id
exec 9>"$base/tarkov-deploy.lock"
flock -w 600 9
test -f "$archive"
test -f "$current/.env" || { echo 'Сначала настройте существующий API и .env' >&2; exit 1; }
test ! -e "$release" || { echo 'Этот ID уже использован; запустите новый attempt' >&2; exit 1; }

# Даже ошибочно упакованный .env или симлинк не должен заменить приватные файлы.
listing=$(tar -tzf "$archive")
if printf '%s\n' "$listing" | grep -E '(^/|(^|/)\.\.(/|$)|(^|/)\.env($|\.)|^(\./)?vendor(/|$))' > /dev/null; then
  echo 'Архив содержит запрещённые пути' >&2; exit 1
fi
if tar -tvzf "$archive" | awk 'substr($0,1,1)!="-" && substr($0,1,1)!="d" {bad=1} END {exit !bad}'; then
  echo 'Архив содержит ссылки или специальные файлы' >&2; exit 1
fi

mkdir -p "$shared" "$releases"
# Первый запуск переносит данные в общий каталог копированием, сохраняя исходный API.
if test ! -f "$shared/.env"; then
  cp "$current/.env" "$shared/.env.tmp"
  chmod 600 "$shared/.env.tmp"
  mv "$shared/.env.tmp" "$shared/.env"
fi
if test ! -d "$shared/storage"; then
  initial_storage=$(mktemp -d "$shared/storage.init.XXXXXX")
  if test -d "$current/storage"; then cp -a "$current/storage/." "$initial_storage/"; fi
  mv "$initial_storage" "$shared/storage"
fi
mkdir "$release"
tar -xzf "$archive" --no-same-owner --no-same-permissions -C "$release"
test -f "$release/public/index.php"
test -f "$release/deployment/alwaysdata/deploy.sh"
restart_script=$release/deployment/alwaysdata/restart-site.sh
# Проверяем доступ до миграций и переключения работающего сайта.
bash "$restart_script" "$base" check
test ! -e "$release/.env"
# В пакете storage содержит только пустые каталоги; никаких rm для общих данных.
if test -n "$(find "$release/storage" -type f -print -quit)"; then
  echo 'В архиве найдены приватные storage-файлы' >&2; exit 1
fi
mv "$release/storage" "$release/storage.empty"
ln -s "$shared/storage" "$release/storage"
ln -s "$shared/.env" "$release/.env"
# Обычная независимая копия исключает повторную тяжёлую распаковку неизменных пакетов.
# Жёсткие ссылки запрещены: Composer нового релиза не должен изменять работающий код.
if test -f "$current/vendor/autoload.php" && cmp -s "$current/composer.lock" "$release/composer.lock" && cmp -s "$current/composer.json" "$release/composer.json"; then
  cp -a "$current/vendor" "$release/vendor"
fi
printf '%s\n' "$release_id" > "$release/REVISION"
ALWAYSDATA_RELEASE_BASE="$base" sh "$release/deployment/alwaysdata/deploy.sh"

previous=$(readlink -f "$current")
legacy=$base/tarkov-api-before-releases
switched=false
restore() {
  status=$?
  trap - EXIT
  if (( status != 0 )) && $switched; then
    ln -s "$previous" "$base/tarkov-api.rollback.$release_id"
    mv -Tf "$base/tarkov-api.rollback.$release_id" "$current"
    bash "$restart_script" "$base" restart || echo 'Не удалось перезапустить PHP после отката; требуется проверка оператора.' >&2
    echo 'Проверка не прошла: возвращён предыдущий код. Данные и схема БД не откатываются.' >&2
  fi
  exit "$status"
}
trap restore EXIT
next=$base/tarkov-api.next.$release_id
ln -s "$release" "$next"
if test ! -L "$current"; then
  test ! -e "$legacy" || { echo 'Каталог сохранённого первоначального API уже существует' >&2; exit 1; }
  mv "$current" "$legacy"
  previous=$legacy
  # При ошибке первого переключения восстанавливаем каталог немедленно.
  if ! mv -T "$next" "$current"; then mv "$legacy" "$current"; exit 1; fi
else
  mv -Tf "$next" "$current"
fi
switched=true
bash "$restart_script" "$base" restart
# Одного HTTP 200 недостаточно: старый PHP тоже может отвечать на /up.
healthy=false
for attempt in {1..10}; do
  headers=$(curl --fail --silent --show-error --connect-timeout 3 --max-time 5 \
    --output /dev/null --dump-header - "$health_url?release=$release_id") || headers=
  if printf '%s\n' "$headers" | tr -d '\r' | grep -Eiq "^X-Wiki-Release: $release_id$"; then healthy=true; break; fi
  sleep 2
done
$healthy || { echo 'Проверка /up не подтвердила новый PHP-релиз.' >&2; exit 1; }
echo "Опубликован API: $release_id"
# Очистка кода не влияет на общие данные и согласованные копии SQLite.
rm -- "$archive"
php "$release/deployment/alwaysdata/prune-releases.php" "$releases" "$release" "$previous" || \
  echo 'Релиз работает, но не удалось очистить старые каталоги кода' >&2
