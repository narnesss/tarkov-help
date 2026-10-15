# Перенос production SQLite в MariaDB alwaysdata

Production: DB_CONNECTION=mysql, база narnes_tarkov на mysql-narnes.alwaysdata.net.
MariaDB >= 10.11, production 11.8; InnoDB/utf8mb4_nopad_bin сохраняет точное сравнение ID.
CONTENT_DIRECTORY=/home/narnes/tarkov-data/content остаётся прежним. SQL содержит ссылки
@file:SHA256, обычные поля и FK. Права Orchid и личный CAS-прогресс сохраняют прежний
текстовый формат API. JSON-колонок контента нет.

## Порядок переключения

1. Опубликовать совместимый код/000005, пока работает SQLite; перезапустить PHP,
   чтобы все новые запросы удерживали DatabaseAccess gate.
2. Workflow migrate-mysql.yml, action prepare: приватный RSA4096 ключ создаётся на origin;
   в журнале только публичный PEM и проверка native CLI/диска.
3. Локально зашифровать JSON реквизитов публичным ключом RSA-OAEP-SHA256.
   Ciphertext workflow — только base64 шифротекста. Пароль не попадает в GitHub Secrets,
   аргументы команд, commits, artifacts или журналы; private key остаётся на origin.
4. Preflight создаёт согласованную VACUUM INTO копию SQLite, штатную схему в пустой цели,
   переносит все таблицы, архивы и сессии, прежние rowid связей и версии CAS.
   Для DOUBLE используется 17 значащих цифр. Сверяются все колонки и SHA-256 каждой строки
   каждой таблицы, внешние ключи и gzip/SHA контента. Сохраняются верхние границы
   sqlite_sequence: удалённые AUTOINCREMENT ID не используются снова.
5. Activate разрешён только после успешного preflight с той же целью. Изменение пробной
   базы извне останавливает перенос до перезаписи. Маркер закрывает новые HTTP-запросы,
   exclusive flock ждёт текущие. Создаётся отдельный финальный SQLite-снимок,
   повторяются загрузка/сверка, создаётся native MySQL SQL gzip-копия.
   Сохраняется .env.before-mysql-* 0600; меняются только DB/content/backup/gate-настройки.
   APP_KEY, proxy-секрет и остальные настройки сохраняются.
6. Перезапустить PHP в панели alwaysdata. Verify проверяет CLI, SELECT 1, FK/контент
   и фактический X-Wiki-Database=mysql от FPM /up. Заголовок доступен только с существующим
   proxy-секретом, который остаётся на сервере и не выводится в журнал.
   Проверить вход и сохранение именованных связей неопубликованного тестового квеста,
   тесты отправить в архив; сверить публичные игровые ID и количество целей.
7. Cleanup удаляет временный ключ шифрования и файл реквизитов.

Приватный wiki.sqlite.mysql-transfer.json содержит спецификации колонок, счётчики/SHA,
отпечаток схемы/цели и пути копий, без паролей и значений аккаунтов.
Источник, финальный снимок и старый .restoring сохраняются. Старый PHP не должен писать
в устаревшую SQLite. Автоматически возвращать .env к SQLite после записи в MySQL запрещено:
это потеряет новые данные. Нужен SQL restore или отдельный согласованный обратный перенос.

## Резервные копии и тесты

Wiki:backup/wiki:restore выбирают сервис по действующему драйверу. MariaDB-копии:
/home/narnes/tarkov-data/mysql-backups/mysql-{daily|deploy}-*.sql.gz.
Native mariadb-dump --single-transaction, приватный манифест со схемой/SHA/файловыми ссылками.
Пароль CLI получает через временный defaults-файл 0600, который удаляется в finally.
Прежние пределы 7 daily/3 deploy/100 МиБ сохраняются; старые копии вытесняются после
проверки новой. Внешняя копия включает весь content.
Restore требует имя/SHA/RESTORE, создаёт safety-копию, закрывает gate, проверяет FK/контент,
при ошибке возвращает safety. После восстановления cookie-сессии отзываются.

CI: SQLite-регрессии, phpunit.mysql.xml на MariaDB11.8/PHP8.4;
scripts/check-mysql-migration.php использует только фиксированную одноразовую CI-базу
tarkov_transfer_test. Проверяет большой прогресс, пароли/сессии, архивы, скрытые rowid,
точные координаты, удалённые ID, защиту изменённой цели и native backup/restore.
