# Исторический план этапа 001

Статус: выполненный первоначальный переход на Laravel/SQLite. Это история решений,
не актуальный production-runbook и не полный перечень сущностей.
Текущие [источники схемы](../data-authority-and-publication.md) и
[деплой MariaDB](../alwaysdata-deployment.md) имеют приоритет над SQLite-шагами ниже.

1. Закрепить Cloudflare workflow и границы PHP/static.
2. Установить Laravel 13, Composer lock, bootstrap, provider и маршруты.
3. Перенести страницы в Blade с общим layout; убрать дубли глубоких HTML из public.
4. Подключить действующие доменные сервисы API через Laravel DI и контроллер.
5. Использовать Laravel migrations для совместимой SQLite-схемы.
6. Добавить Laravel Cache для публичного каталога и страниц, безопасные cache headers.
7. Artisan-экспорт, проверка свежести экспорта в Node, Linguist generated.
8. Проверить HTTP, кэш/инвалидацию, приватность, экспорт, CI и запушить.

Проверка конституции: Laravel и PHPDoc — PASS; Bitrix/install mirror — N/A,
это самостоятельный Tarkov-проект; SQL-схема — Laravel migrations; карты классов/БД
обновляются в docs/architecture; секреты и приватные данные исключены из Git.
