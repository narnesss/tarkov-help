# Запуск

composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
php artisan serve

Для существующей БД: DB_DATABASE=/absolute/path/var/wiki.sqlite перед migrate.
Экспорт: php artisan site:export; npm run build; npm test.
Production: APP_DEBUG=false, APP_URL=https://domain, composer install --no-dev,
php artisan migrate --force, php artisan optimize. Корень веб-сервера — public.
После изменений deployment выполнять php artisan optimize:clear и optimize.
