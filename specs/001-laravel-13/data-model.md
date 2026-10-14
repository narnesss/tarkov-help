# Данные

users(id, username nullable unique, password_hash nullable, created_at).
sessions(token_hash, user_id FK, expires_at); токены в БД только SHA-256.
user_state(user_id FK, state_key, value JSON, version, updated_at): PK owner+key.
rate_limits(bucket, count, expires_at). Существующие записи и версии сохраняются.
Миграция принимает существующие таблицы; rollback запрещён, чтобы не удалять прогресс.
Laravel Cache хранится в storage/framework/cache, вне public и Git; данные профилей
не попадают в этот кэш. APP_STORAGE совместим с прежним хранилищем приложения.
