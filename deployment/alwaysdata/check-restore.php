<?php
declare(strict_types=1);

use App\Console\Commands\{BackupSqlite, RestoreSqlite};
use App\Services\{DatabaseAccess, SqliteBackups};
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Отдельный Laravel-контейнер: production bootstrap, .env и config cache НЕ читаются.
require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensure(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

/** Команды те же, что у artisan; единственное подключение задано тестовым контейнером. */
function command(Application $app, string $class, array $options = [], int $expected = 0): string
{
    $command = new $class;
    $command->setLaravel($app);
    $input = new ArrayInput($options);
    $input->setInteractive(false);
    $output = new BufferedOutput;
    ensure($command->run($input, $output) === $expected, 'Неожиданный код завершения '.$class);
    return $output->fetch();
}

/** Только содержимое созданной здесь тестовой БД, включая сессии и журнал миграций. */
function image(PDO $pdo): array
{
    $rows = [];
    foreach (['users', 'sessions', 'user_state', 'rate_limits', 'migrations'] as $table) {
        $rows[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $rows;
}

$directory = null; $app = null; $writer = null; $copy = null; $exit = 1;
umask(0077);
try {
    ensure(PHP_SAPI === 'cli' && PHP_OS_FAMILY !== 'Windows', 'Нужен Linux PHP CLI с pdo_sqlite и sqlite3.');
    ensure($argc === 1 && class_exists(SQLite3::class), 'Аргументы и пути к чужим БД запрещены; нужно sqlite3.');
    // На alwaysdata HOME находится на той же сетевой FS, что и рабочая БД.
    // CI использует свой HOME. Принимается только существующий домашний каталог.
    $home = getenv('HOME');
    ensure(is_string($home) && str_starts_with($home, '/') && is_dir($home), 'HOME должен быть абсолютным каталогом.');
    $home = realpath($home);
    $candidate = $home.'/tarkov-restore-test-'.bin2hex(random_bytes(12));
    ensure(mkdir($candidate, 0700), 'Не удалось создать закрытый тестовый каталог.');
    $directory = $candidate;
    $database = $directory.'/fixture.sqlite';
    $writer = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $writer->exec('PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0; PRAGMA foreign_keys=ON');
    $app = new Application($directory);
    $app->instance('env', 'testing');
    $app->instance('config', new Repository([
        'app' => ['env' => 'testing', 'debug' => false],
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '',
            'foreign_key_constraints' => true, 'busy_timeout' => 5000, 'journal_mode' => 'WAL',
        ]]],
        'backup' => ['daily_keep' => 1, 'deploy_keep' => 3, 'max_bytes' => 4 * 1024 * 1024],
    ]));
    Facade::setFacadeApplication($app);
    $app->register(DatabaseServiceProvider::class);
    ensure($app['db']->connection()->getDatabaseName() === $database, 'Нарушена изоляция SQLite.');
    // Реальная миграция приложения, без migrate/fresh и без подключения production kernel.
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_15_000001_preserve_wiki_storage.php';
    $migration->up();
    $writer->exec("CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER);
        INSERT INTO migrations VALUES (1, '2026_10_15_000001_preserve_wiki_storage', 1);
        INSERT INTO users VALUES ('fixture-before', 'fixture-before', 'test-only-hash', 1);
        INSERT INTO user_state VALUES ('fixture-before', 'progress', '{\"step\":1}', 1, 1);
        INSERT INTO sessions VALUES ('".str_repeat('a', 64)."', 'fixture-before', 9999999999)");
    $before = image($writer);
    $inode = fileinode($database);
    $service = new SqliteBackups($database, 1, 3, 4 * 1024 * 1024);
    command($app, BackupSqlite::class);
    command($app, BackupSqlite::class);
    $catalog = $service->catalog();
    ensure(count($catalog) === 1 && $catalog[0]['valid'], 'Daily создал дубликат или неисправную копию.');
    $selected = $catalog[0];
    echo "PASS: daily backup, повтор без дубликата, quick_check/FK\n";

    $writer->exec("INSERT INTO users VALUES ('fixture-after', 'fixture-after', 'test-only-hash', 2);
        UPDATE user_state SET value='{\"step\":2}', version=2, updated_at=2;
        INSERT INTO sessions VALUES ('".str_repeat('b', 64)."', 'fixture-after', 9999999999)");
    clearstatcache();
    ensure(is_file($database.'-wal') && filesize($database.'-wal') > 0, 'Тест не создал активный WAL.');
    $after = image($writer);
    $options = ['--backup' => $selected['name'], '--sha256' => $selected['sha256']];
    ensure(str_contains(command($app, RestoreSqlite::class, ['--list' => true]), $selected['sha256']), 'CLI list потерял SHA-256.');
    command($app, RestoreSqlite::class, $options + ['--check' => true]);
    command($app, RestoreSqlite::class, $options, 1);
    $rejected = false;
    try { command($app, RestoreSqlite::class, ['--sha256' => str_repeat('0', 64), '--backup' => $selected['name'], '--confirm' => 'RESTORE']); }
    catch (RuntimeException $error) { $rejected = str_contains($error->getMessage(), 'Контрольная сумма'); }
    ensure($rejected && image($writer) === $after && count($service->catalog()) === 1, 'Read-only/отказ изменил данные или создал копии.');
    echo "PASS: CLI list/check, отказ без подтверждения и при неверном SHA-256\n";

    $gate = new DatabaseAccess($database);
    $lock = $gate->acquire();
    ensure(is_resource($lock), 'Shared flock не работает на файловой системе сервера.');
    try { ensure($gate->acquire(exclusive: true) === null, 'Exclusive не ждёт shared-запрос.'); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    $lock = $gate->acquire(exclusive: true);
    ensure(is_resource($lock), 'Не получен exclusive flock.');
    try { ensure($gate->acquire() === null, 'Shared пропускает запрос во время exclusive.'); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    echo "PASS: shared/exclusive flock на файловой системе alwaysdata/CI\n";

    $output = command($app, RestoreSqlite::class, $options + ['--confirm' => 'RESTORE']);
    ensure(preg_match('/сохранена: (fixture\.sqlite\.backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{6})/u', $output, $match) === 1, 'CLI не вернул safety-копию.');
    $safety = $directory.'/'.$match[1];
    $copy = new PDO('sqlite:'.$safety);
    ensure(image($copy) === $after, 'Safety-копия потеряла текущие аккаунты, прогресс или WAL.');
    $copy = null;
    $before['sessions'] = [];
    ensure(image($writer) === $before, 'Выбранный снимок восстановлен неверно или старые сессии ожили.');
    ensure(!file_exists($gate->marker()), 'После успеха API остался закрыт.');
    echo "PASS: restore выбранной копии; текущие данные/WAL сохранены, старые сессии сброшены\n";

    command($app, RestoreSqlite::class, ['--backup' => basename($safety), '--sha256' => hash_file('sha256', $safety), '--confirm' => 'RESTORE']);
    $after['sessions'] = [];
    ensure(image($writer) === $after, 'Обратное восстановление safety-копии потеряло данные.');
    echo "PASS: обратное восстановление сохранённой текущей базы\n";

    $failed = false;
    try {
        $service->restore($selected['name'], $selected['sha256'], function () use ($gate): void {
            ensure(file_exists($gate->marker()) && $gate->acquire() === null, 'Маркер не закрыл доступ во время restore.');
            throw new RuntimeException('fixture-forced-validation-failure');
        });
    } catch (RuntimeException $error) { $failed = str_contains($error->getMessage(), 'fixture-forced-validation-failure'); }
    ensure($failed && image($writer) === $after && !file_exists($gate->marker()), 'Аварийный возврат не сохранил исходное состояние.');
    clearstatcache();
    ensure(fileinode($database) === $inode && $writer->query('PRAGMA quick_check')->fetchColumn() === 'ok', 'Изменён inode или повреждена SQLite.');
    $rotation = $service->create('deploy');
    ensure($rotation['deleted'] === 1 && $rotation['count'] === 4, 'Ротация не удалила лишний deploy-снимок.');
    $catalog = $service->catalog();
    ensure(count($catalog) <= 4 && array_sum(array_column($catalog, 'bytes')) <= 4 * 1024 * 1024, 'Нарушен лимит копий.');
    foreach ($catalog as $entry) {
        ensure($entry['valid'] && (fileperms($directory.'/'.$entry['name']) & 0777) === 0600, 'Копия неисправна или открыта другим пользователям.');
    }
    ensure(glob($database.'.restore-*.partial') === [], 'Остался незавершённый restore.');
    echo "PASS: аварийный возврат, маркер, inode/WAL, целостность, права 0600 и ротация\n";
    $exit = 0;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: '.$error->getMessage()."\n");
} finally {
    if ($app !== null && $app->bound('db')) { $app['db']->disconnect(); }
    $writer = null; $copy = null;
    // Только собственный случайный каталог: без рекурсии и следования по ссылкам.
    if ($directory !== null && is_dir($directory) && !is_link($directory)) {
        try {
            foreach (new FilesystemIterator($directory) as $entry) {
                ensure(!$entry->isDir() || $entry->isLink(), 'Неожиданный вложенный каталог: очистка прекращена.');
                ensure(unlink($entry->getPathname()), 'Не удалось удалить тестовый файл.');
            }
            ensure(rmdir($directory), 'Не удалось удалить тестовый каталог.');
            echo "PASS: тестовые файлы удалены; рабочая SQLite/.env не открывались\n";
        } catch (Throwable $error) { fwrite(STDERR, 'FAIL cleanup: '.$error->getMessage()."\n"); $exit = 1; }
    }
}
exit($exit);
