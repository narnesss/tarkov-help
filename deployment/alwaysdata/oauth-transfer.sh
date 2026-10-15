#!/usr/bin/env bash
# Приватный ключ остаётся на alwaysdata; CI получает только открытый ключ и шифротекст.
set -Eeuo pipefail
umask 077
mode=${1:?}
exec 9>"$HOME/tarkov-deploy.lock"
flock -w 600 9
cd "$HOME/tarkov-api"
private="$HOME/tarkov-data/oauth-setup"
mkdir -p "$private"
chmod 700 "$private"
case "$mode" in
  prepare)
    test ! -f "$private/private.pem" || { echo 'Сначала используйте существующий ключ или cleanup.' >&2; exit 1; }
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out "$private/private.pem" 2>/dev/null
    openssl pkey -in "$private/private.pem" -pubout
    ;;
  apply)
    test -f "$private/private.pem"
    trap 'rm -f -- "$private/access.json" "$private/encrypted.bin" "$private/private.pem"' EXIT
    base64 --decode > "$private/encrypted.bin"
    openssl pkeyutl -decrypt -inkey "$private/private.pem" -in "$private/encrypted.bin" -out "$private/access.json" -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 -pkeyopt rsa_mgf1_md:sha256
    php deployment/alwaysdata/configure-player-oauth.php "$private/access.json"
    php artisan config:cache
    ;;
  cleanup)
    rm -f -- "$private/private.pem" "$private/access.json" "$private/encrypted.bin"
    echo 'Временные ключи настройки OAuth удалены.'
    ;;
  *) exit 1 ;;
esac
