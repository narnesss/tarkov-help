#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
action=${1:-list}
backup=${2:-}
sha=${3:-}
confirmed=${4:-false}
exec 9>"$HOME/tarkov-deploy.lock"
flock -w 600 9
cd "$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
case "$action" in
  list) exec php artisan wiki:restore --list --no-interaction ;;
  check) exec php artisan wiki:restore --check --backup="$backup" --sha256="$sha" --no-interaction ;;
  restore)
    [[ $confirmed = true ]] || { echo 'Подтвердите замену текущих данных выбранным снимком' >&2; exit 1; }
    exec php artisan wiki:restore --backup="$backup" --sha256="$sha" --confirm=RESTORE --no-interaction ;;
  *) echo 'Неизвестное действие восстановления' >&2; exit 1 ;;
esac
