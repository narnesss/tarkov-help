#!/usr/bin/env bash
# Пароль передаётся только RSA-OAEP ciphertext; приватный ключ остаётся на origin.
set -Eeuo pipefail
umask 077
mode=${1:?}
exec 9>"$HOME/tarkov-deploy.lock"
flock -w 600 9
cd "$HOME/tarkov-api"
private="$HOME/tarkov-data/mysql-transfer"
mkdir -p "$private"
chmod 700 "$private"
case "$mode" in
  prepare)
    test ! -f "$private/private.pem" || { echo 'Ключ переноса уже существует; сначала используйте его или cleanup.' >&2; exit 1; }
    command -v mariadb-dump || command -v mysqldump
    command -v mariadb || command -v mysql
    php -r 'foreach (["pdo_mysql","pdo_sqlite","openssl"] as $e) { if (!extension_loaded($e)) { throw new RuntimeException($e); } }'
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out "$private/private.pem" 2>/dev/null
    openssl pkey -in "$private/private.pem" -pubout
    df -m "$HOME/tarkov-data"
    ;;
  preflight|activate)
    test -f "$private/private.pem"
    trap 'rm -f -- "$private/access.json" "$private/encrypted.bin"' EXIT
    base64 --decode > "$private/encrypted.bin"
    openssl pkeyutl -decrypt -inkey "$private/private.pem" -in "$private/encrypted.bin" -out "$private/access.json" -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 -pkeyopt rsa_mgf1_md:sha256
    if [[ $mode = activate ]]; then php artisan wiki:migrate-mysql "$private/access.json" --activate --no-interaction
    else php artisan wiki:migrate-mysql "$private/access.json" --no-interaction; fi
    ;;
  verify)
    php deployment/alwaysdata/verify-mysql.php
    ;;
  cleanup)
    rm -f -- "$private/private.pem" "$private/access.json" "$private/encrypted.bin"
    echo 'Временный ключ шифрования и реквизиты удалены.'
    ;;
  *) exit 1 ;;
esac
