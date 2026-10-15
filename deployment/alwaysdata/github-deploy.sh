#!/usr/bin/env bash
# Deploy после CI main; backup — ежедневное задание на временном GitHub runner.
set -Eeuo pipefail
umask 077
mode=${1:-deploy}
[[ $mode = deploy || $mode = backup || $mode = restore ]] || { echo 'Нужен режим deploy, backup или restore' >&2; exit 1; }
if [[ $mode = restore ]]; then
  [[ ${RESTORE_ACTION:-} = list || ${RESTORE_ACTION:-} = check || ${RESTORE_ACTION:-} = restore ]] || exit 1
  if [[ $RESTORE_ACTION != list ]]; then
    [[ ${RESTORE_BACKUP:-} =~ ^wiki\.sqlite\.backup-(daily-)?[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$ ]] || { echo 'Недопустимое имя копии' >&2; exit 1; }
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
if [[ $mode = restore ]]; then
  ssh "${ssh_options[@]}" "$target" "bash /home/narnes/tarkov-api/deployment/alwaysdata/restore.sh '$RESTORE_ACTION' '$RESTORE_BACKUP' '$RESTORE_SHA256' '${RESTORE_CONFIRMED:-false}'"
  exit
fi
if [[ $mode = backup ]]; then
  ssh "${ssh_options[@]}" "$target" 'sh /home/narnes/tarkov-api/deployment/alwaysdata/backup.sh'
  exit
fi
ssh "${ssh_options[@]}" "$target" 'umask 077; mkdir -p "$HOME/tarkov-incoming"'
scp "${ssh_options[@]}" build/alwaysdata-api.tar.gz "$target:tarkov-incoming/$release_id.tar.gz"
ssh "${ssh_options[@]}" "$target" "bash -s -- '$release_id'" < deployment/alwaysdata/release.sh
