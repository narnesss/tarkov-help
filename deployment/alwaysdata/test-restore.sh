#!/usr/bin/env bash
# Изолированный тест на FS alwaysdata; рабочая БД и Laravel .env не загружаются.
set -Eeuo pipefail
umask 077
[[ $# = 1 && $1 =~ ^[a-f0-9]{40}$ ]] || { echo 'Нужен SHA проверяемого релиза' >&2; exit 1; }
exec 9>"$HOME/tarkov-deploy.lock"
flock -w 600 9
cd "$(dirname "$0")/../.."
revision=$(cat REVISION)
[[ $revision = "$1"-* ]] || { echo 'На сервере другой релиз; дождитесь деплоя этого SHA' >&2; exit 1; }
printf 'Проверяемый релиз: %s\n' "$revision"
php deployment/alwaysdata/check-restore.php
