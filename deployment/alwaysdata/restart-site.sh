#!/usr/bin/env bash
# Ключ находится вне релиза; ни argv curl, ни журнал не содержат секрет.
set -Eeuo pipefail
base=${1:?Нужен каталог аккаунта}
action=${2:-restart}
[[ $action = check || $action = restart ]] || exit 1
key_file=$base/tarkov-shared/alwaysdata-api-key
[[ -f $key_file && ! -L $key_file && $(stat -c %u "$key_file") = $(id -u) && $(stat -c %a "$key_file") = 600 ]] || {
  echo 'Нужен приватный alwaysdata-api-key с правами 600 в tarkov-shared.' >&2; exit 1;
}
key=$(cat "$key_file")
[[ $key =~ ^[A-Za-z0-9._~-]{16,256}$ ]] || { echo 'Некорректный API-ключ alwaysdata.' >&2; exit 1; }
method=POST
endpoint=https://api.alwaysdata.com/v1/site/1086865/restart/
if [[ $action = check ]]; then method=GET; endpoint=https://api.alwaysdata.com/v1/site/1086865/; fi
for attempt in 1 2 3; do
  code=000
  code=$(printf 'user = "%s account=narnes:"\n' "$key" | curl --config - --basic --request "$method" \
    --silent --show-error --connect-timeout 5 --max-time 15 --output /dev/null --write-out '%{http_code}' "$endpoint") || code=000
  if [[ $code = 200 || $code = 202 || $code = 204 ]]; then
    if [[ $action = restart ]]; then echo 'Перезапуск PHP запрошен у alwaysdata.'; fi
    exit 0
  fi
  if [[ $code != 000 && $code != 429 && $code != 5?? ]]; then break; fi
  if (( attempt < 3 )); then sleep 2; fi
done
echo "alwaysdata: не удалось выполнить $action (HTTP $code)." >&2
exit 1
