#!/usr/bin/env bash
# Deploy после CI main; backup — ежедневное задание на временном GitHub runner.
set -Eeuo pipefail
umask 077
mode=${1:-deploy}
[[ $mode = deploy || $mode = backup || $mode = restore || $mode = test-restore || $mode = mysql-transfer || $mode = oauth-transfer || $mode = storage-check || $mode = storage-clean ]] || { echo 'Неизвестный режим' >&2; exit 1; }
if [[ $mode = restore ]]; then
  [[ ${RESTORE_ACTION:-} = list || ${RESTORE_ACTION:-} = check || ${RESTORE_ACTION:-} = restore ]] || exit 1
  if [[ $RESTORE_ACTION != list ]]; then
    [[ ${RESTORE_BACKUP:-} =~ ^wiki\.sqlite\.backup-(daily-)?[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$ || ${RESTORE_BACKUP:-} =~ ^mysql-(daily|deploy)-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.sql\.gz$ ]] || { echo 'Недопустимое имя копии' >&2; exit 1; }
    [[ ${RESTORE_SHA256:-} =~ ^[a-f0-9]{64}$ ]] || { echo 'Нужен SHA-256 из списка' >&2; exit 1; }
  else
    RESTORE_BACKUP= RESTORE_SHA256=
  fi
  if [[ $RESTORE_ACTION = restore && ${RESTORE_CONFIRMED:-false} != true ]]; then
    echo 'Подтвердите восстановление в форме workflow' >&2; exit 1
  fi
  if [[ $RESTORE_ACTION != restore ]]; then RESTORE_CONFIRMED=false; fi
fi
: "${ALWAYSDATA_SSH_KEY:?Добавьте GitHub secret ALWAYSDATA_SSH_KEY}"
: "${ALWAYSDATA_KNOWN_HOSTS:?Добавьте GitHub secret ALWAYSDATA_KNOWN_HOSTS}"
: "${GITHUB_SHA:?}" "${GITHUB_RUN_ID:?}" "${GITHUB_RUN_ATTEMPT:?}" "${RUNNER_TEMP:?}"
release_id=$GITHUB_SHA-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
[[ $release_id =~ ^[a-f0-9]{40}-[0-9]+-[0-9]+$ ]] || exit 1
ssh_dir=$(mktemp -d "$RUNNER_TEMP/alwaysdata-ssh.XXXXXX")
trap 'rm -rf -- "$ssh_dir"' EXIT
printf '%s\n' "$ALWAYSDATA_SSH_KEY" > "$ssh_dir/key"
printf '%s\n' "$ALWAYSDATA_KNOWN_HOSTS" > "$ssh_dir/known_hosts"
unset ALWAYSDATA_SSH_KEY ALWAYSDATA_KNOWN_HOSTS
ssh_options=(-i "$ssh_dir/key" -o BatchMode=yes -o IdentitiesOnly=yes
  -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$ssh_dir/known_hosts" -o ConnectTimeout=20)
target=narnes@ssh-narnes.alwaysdata.net
if [[ $mode = storage-check || $mode = storage-clean ]]; then
  # Только размеры известных каталогов; SQL, content, shared и личные файлы не удаляются.
  ssh "${ssh_options[@]}" "$target" "bash -s -- '$mode'" <<'STORAGE'
set -euo pipefail
mode=$1
exec 9>"$HOME/tarkov-deploy.lock"
flock -w 60 9
report() {
  quota -s 2>/dev/null || true
  for directory in tarkov-releases tarkov-incoming tarkov-shared tarkov-data .cache/composer .composer/cache; do
    if test -d "$HOME/$directory"; then du -sk -- "$HOME/$directory"; fi
  done
}
report
if [[ $mode = storage-clean ]]; then
  composer clear-cache --no-interaction
  if test -d "$HOME/tarkov-releases"; then
    php "$HOME/tarkov-api/deployment/alwaysdata/prune-releases.php" "$HOME/tarkov-releases" "$HOME/tarkov-api" "$HOME/tarkov-api"
  fi
  report
fi
STORAGE
  exit
fi
if [[ $mode = oauth-transfer ]]; then
  [[ ${OAUTH_ACTION:-} = prepare || ${OAUTH_ACTION:-} = apply || ${OAUTH_ACTION:-} = cleanup ]] || exit 1
  [[ ${OAUTH_CIPHERTEXT:-} =~ ^[A-Za-z0-9+/=]*$ ]] || exit 1
  printf '%s' "${OAUTH_CIPHERTEXT:-}" | ssh "${ssh_options[@]}" "$target" "bash /home/narnes/tarkov-api/deployment/alwaysdata/oauth-transfer.sh '$OAUTH_ACTION'"
  exit
fi
if [[ $mode = mysql-transfer ]]; then
  [[ ${TRANSFER_ACTION:-} = prepare || ${TRANSFER_ACTION:-} = preflight || ${TRANSFER_ACTION:-} = activate || ${TRANSFER_ACTION:-} = verify || ${TRANSFER_ACTION:-} = cleanup ]] || exit 1
  [[ ${TRANSFER_CIPHERTEXT:-} =~ ^[A-Za-z0-9+/=]*$ ]] || exit 1
  printf '%s' "${TRANSFER_CIPHERTEXT:-}" | ssh "${ssh_options[@]}" "$target" "bash /home/narnes/tarkov-api/deployment/alwaysdata/mysql-transfer.sh '$TRANSFER_ACTION'"
  exit
fi
if [[ $mode = test-restore ]]; then
  ssh "${ssh_options[@]}" "$target" "bash /home/narnes/tarkov-api/deployment/alwaysdata/test-restore.sh '$GITHUB_SHA'"
  exit
fi
if [[ $mode = restore ]]; then
  ssh "${ssh_options[@]}" "$target" "bash /home/narnes/tarkov-api/deployment/alwaysdata/restore.sh '$RESTORE_ACTION' '$RESTORE_BACKUP' '$RESTORE_SHA256' '${RESTORE_CONFIRMED:-false}'"
  exit
fi
if [[ $mode = backup ]]; then
  ssh "${ssh_options[@]}" "$target" 'sh /home/narnes/tarkov-api/deployment/alwaysdata/backup.sh'
  exit
fi
# Освобождаем место до загрузки/копирования vendor. Тот же flock исключает чужой текущий релиз.
: "${ALWAYSDATA_API_KEY:?Добавьте GitHub secret ALWAYSDATA_API_KEY для перезапуска PHP}"
[[ $ALWAYSDATA_API_KEY =~ ^[A-Za-z0-9._~-]{16,256}$ ]] || { echo 'Некорректный API-ключ alwaysdata' >&2; exit 1; }
ssh "${ssh_options[@]}" "$target" 'if test -d "$HOME/tarkov-releases"; then flock -w 600 "$HOME/tarkov-deploy.lock" php /dev/stdin "$HOME/tarkov-releases" "$HOME/tarkov-api" "$HOME/tarkov-api"; fi' < deployment/alwaysdata/prune-releases.php
# Секрет передаётся только через stdin SSH и атомарно сохраняется вне web root и релизов.
printf '%s\n' "$ALWAYSDATA_API_KEY" | ssh "${ssh_options[@]}" "$target" 'set -eu; umask 077; mkdir -p "$HOME/tarkov-shared"; key_tmp=$(mktemp "$HOME/tarkov-shared/.alwaysdata-api-key.XXXXXX"); trap '\''rm -f -- "$key_tmp"'\'' EXIT; cat > "$key_tmp"; chmod 600 "$key_tmp"; mv -f "$key_tmp" "$HOME/tarkov-shared/alwaysdata-api-key"'
unset ALWAYSDATA_API_KEY
ssh "${ssh_options[@]}" "$target" 'umask 077; mkdir -p "$HOME/tarkov-incoming"'
scp "${ssh_options[@]}" build/alwaysdata-api.tar.gz "$target:tarkov-incoming/$release_id.tar.gz"
ssh "${ssh_options[@]}" "$target" "bash -s -- '$release_id'" < deployment/alwaysdata/release.sh
