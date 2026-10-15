#!/usr/bin/env sh
# Выполнять в каталоге загруженного API после настройки приватного .env.
set -eu
umask 077
cd "$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
test -f .env || { echo 'Сначала создайте .env из deployment/alwaysdata/environment.example'; exit 1; }
php -r 'if (PHP_VERSION_ID < 80400) { fwrite(STDERR, "Выберите PHP 8.4 в alwaysdata\n"); exit(1); } foreach (["pdo_sqlite", "sqlite3", "mbstring", "curl", "dom", "fileinfo", "xml"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Нет расширения: $e\n"); exit(1); } } $pdo = new PDO("sqlite::memory:"); if (version_compare($pdo->query("select sqlite_version()")->fetchColumn(), "3.35.0", "<")) { fwrite(STDERR, "Нужна SQLite >= 3.35 для RETURNING\n"); exit(1); }'
mkdir -p storage/framework/cache/data storage/framework/cache/locks storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
php artisan config:clear
php deployment/alwaysdata/prepare.php
php artisan migrate --force
php artisan wiki:import-content
# Совместимое чтение файлов уже опубликовано предыдущим релизом.
php artisan wiki:content-files --move
php artisan vendor:publish --tag=orchid-assets --force
php artisan optimize
echo 'API подготовлен. Проверьте HTTPS /up и настройте секрет Worker перед включением API_ENABLED.'
