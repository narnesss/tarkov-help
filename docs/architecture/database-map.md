# Карта БД Tarkov Wiki

Владелец схемы: database/migrations/2026_10_15_000001_preserve_wiki_storage.php.
Laravel connection: config/database.php, SQLite DB_DATABASE либо APP_STORAGE/wiki.sqlite.
users: string id PK, username nullable UNIQUE, password_hash nullable, created_at.
sessions: token_hash PK (SHA-256), user_id FK users.id, expires_at INDEX.
user_state: PK(user_id,state_key), user_id FK users.id, JSON value, version, updated_at.
rate_limits: bucket PK, count, expires_at. migrations: штатный журнал Laravel.
Ранее существующие таблицы принимаются без очистки; копировать БД перед migrate.
Rollback схемы намеренно запрещён, чтобы не удалять пользовательский прогресс.
Прямые prepared SQL сохранены только в проверенных src-сервисах CAS/сессий;
они выполняются через PDO соединения Laravel. Новые schema изменения — migrations.
Публичный файловый Cache и скомпилированные Blade хранятся в storage/framework,
вне web root. Ключи/БД/личные данные никогда не экспортируются в dist.
