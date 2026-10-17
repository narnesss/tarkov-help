# Историческая модель первоначального переноса

Статус: исторический снимок этапа 001 (SQLite), не полная текущая схема и не инструкция production.
Актуальный источник — [все миграции](../../database/migrations/) и выбираемый ими DDL MariaDB;
[контракт источников данных](../data-authority-and-publication.md) задаёт разделение SQL/файлов.
Навигация: [карта БД](../../docs/architecture/database-map.md), [текущий деплой](../alwaysdata-deployment.md).
Ниже перечислены только первоначальные таблицы; последующие wiki_*, Orchid, OAuth, профили,
почта, восстановление пароля, история паролей и согласия определяются миграциями.
Обозначение value JSON ниже означает сериализованное состояние, не native JSON-колонку контента.

## Исходный снимок

users(id, username nullable unique, password_hash nullable, created_at).
sessions(token_hash, user_id FK, expires_at); токены в БД только SHA-256.
user_state(user_id FK, state_key, value JSON, version, updated_at): PK owner+key.
rate_limits(bucket, count, expires_at). Существующие записи и версии сохраняются.
Миграция принимает существующие таблицы; rollback запрещён, чтобы не удалять прогресс.
Laravel Cache хранится в storage/framework/cache, вне public и Git; данные профилей
не попадают в этот кэш. APP_STORAGE совместим с прежним хранилищем приложения.
