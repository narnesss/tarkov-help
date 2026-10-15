<?php
declare(strict_types=1);

// Проверка настоящего API-пакета: production deploy дважды сохраняет ключ и записи SQLite.
$root = dirname(__DIR__);
$package = $root.'/build/alwaysdata-api';
$database = $root.'/build/alwaysdata-test-data-'.bin2hex(random_bytes(6)).'/wiki.sqlite';
if (!is_dir($package) || file_exists($package.'/.env')) { throw new RuntimeException('Нужен свежий npm run package:alwaysdata.'); }
$run = function (array $command) use ($package): string {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes, $package);
    if (!is_resource($process)) { throw new RuntimeException('Не удалось запустить проверку.'); }
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Ошибка проверки: '.implode(' ', $command)); }
    return $output;
};
$environment = str_replace('DB_DATABASE=/home/narnes/tarkov-data/wiki.sqlite', 'DB_DATABASE='.$database,
    file_get_contents($package.'/deployment/alwaysdata/environment.example'));
$environment = str_replace('API_PROXY_TOKEN=', 'API_PROXY_TOKEN='.str_repeat('test-', 13), $environment);
file_put_contents($package.'/.env', $environment); chmod($package.'/.env', 0600);
$run(['sh', 'deployment/alwaysdata/deploy.sh']);
$before = file_get_contents($package.'/.env');
// Ошибка standalone PHP-подготовки обязана остановить shell deploy ненулевым кодом.
file_put_contents($database.'.restoring', 'test-only-block');
try {
    $process = proc_open([PHP_BINARY, 'deployment/alwaysdata/prepare.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $package);
    if (!is_resource($process) || proc_close($process) === 0) { throw new RuntimeException('Ошибка prepare.php не остановила деплой.'); }
} finally { unlink($database.'.restoring'); }
if (!preg_match('/^APP_KEY=base64:.+$/m', $before)) { throw new RuntimeException('APP_KEY не создан.'); }
$pdo = new PDO('sqlite:'.$database);
$pdo->prepare('INSERT INTO users(id, username, password_hash, created_at) VALUES (?, ?, NULL, ?)')->execute(['keep-this-user', 'deploy-probe', time()]);
$legacyTask = ['id' => 'legacy-deploy-probe', 'name' => 'Сохранённая правка', 'objectives' => [['id' => 'stable-goal', 'description' => 'Текст цели']]];
$pdo->prepare('INSERT INTO quests(quest_id, mode, name, published, payload, updated_at) VALUES (?, ?, ?, 0, ?, ?)')
    ->execute(['legacy-deploy-probe', 'pve', 'Сохранённая правка', json_encode($legacyTask), '2026-10-01 00:00:00']);
$pdo = null;
$run(['sh', 'deployment/alwaysdata/deploy.sh']);
if (file_get_contents($package.'/.env') !== $before) { throw new RuntimeException('Обновление изменило APP_KEY или .env.'); }
$pdo = new PDO('sqlite:'.$database);
if ((int) $pdo->query("SELECT count(*) FROM users WHERE id='keep-this-user'")->fetchColumn() !== 1) { throw new RuntimeException('Обновление потеряло данные.'); }
$reference = $pdo->query("SELECT payload FROM quests WHERE quest_id='legacy-deploy-probe'")->fetchColumn();
require_once $root.'/vendor/autoload.php';
if (!str_starts_with($reference, '@file:') || \App\Services\ContentFiles::forDatabase($database)->read($reference) !== $legacyTask
    || $pdo->query("SELECT updated_at FROM quests WHERE quest_id='legacy-deploy-probe'")->fetchColumn() !== '2026-10-01 00:00:00') {
    throw new RuntimeException('Перенос JSON в файлы потерял правки, ID целей или даты.');
}
foreach (['quests', 'content_datasets'] as $table) {
    if ($pdo->query('SELECT count(*) FROM '.$table." WHERE payload NOT LIKE '@file:%'")->fetchColumn() > 0) { throw new RuntimeException('JSON контента остался в SQLite.'); }
}
$backups = glob($database.'.backup-*');
if (count($backups) !== 1) { throw new RuntimeException('Не создана резервная копия перед обновлением.'); }
$backup = new PDO('sqlite:'.$backups[0]);
if ((int) $backup->query("SELECT count(*) FROM users WHERE id='keep-this-user'")->fetchColumn() !== 1) { throw new RuntimeException('Резервная копия потеряла данные.'); }
$routes = json_decode($run([PHP_BINARY, 'artisan', 'route:list', '--json']), true, flags: JSON_THROW_ON_ERROR);
foreach ($routes as $route) {
    if (!in_array($route['uri'], ['up', 'api/{path?}', 'api/content/data/{path}', 'api/content/quest/{quest}', 'api/auth/options', 'api/auth/profile', 'api/auth/oauth/{provider}/start', 'api/auth/oauth/{provider}/finish'], true)
        && !str_starts_with($route['uri'], 'admin')) { throw new RuntimeException('API-пакет публикует лишний маршрут: '.$route['uri']); }
}
echo "PASS: production API-пакет, повторный deploy, APP_KEY, SQLite backup и API_ONLY маршруты.\n";

// Первый автоматический релиз, повторное обновление и откат кода после ошибки /up.
$base = dirname($database).'/host';
mkdir($base.'/tarkov-api/public', 0700, true);
mkdir($base.'/tarkov-api/storage/logs', 0700, true);
mkdir($base.'/tarkov-incoming', 0700, true);
file_put_contents($base.'/tarkov-api/.env', $before);
file_put_contents($base.'/tarkov-api/public/index.php', '<?php http_response_code(503);');
file_put_contents($base.'/tarkov-api/storage/logs/preserve.txt', 'keep-storage');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorText);
if ($socket === false) { throw new RuntimeException($errorText); }
$address = stream_socket_get_name($socket, false); fclose($socket);
$server = proc_open([PHP_BINARY, '-S', $address, '-t', $base.'/tarkov-api/public', $base.'/tarkov-api/public/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $base.'/server.log', 'a'], 2 => ['file', $base.'/server.log', 'a']], $serverPipes, $base);
if (!is_resource($server)) { throw new RuntimeException('Нет HTTP-сервера проверки.'); }
$automatic = function (int $attempt, bool $fail = false) use ($root, $base, $address): array {
    $id = str_repeat('a', 40).'-1-'.$attempt;
    copy($root.'/build/alwaysdata-api.tar.gz', $base.'/tarkov-incoming/'.$id.'.tar.gz');
    $process = proc_open(['bash', $root.'/deployment/alwaysdata/release.sh', $id, $base,
        'http://'.$address.($fail ? '/route-that-must-not-exist' : '/up')],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes, $root);
    if (!is_resource($process)) { throw new RuntimeException('Не удалось проверить автоматический релиз.'); }
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    return [proc_close($process), $id, $output];
};
try {
    foreach ([1, 2] as $attempt) {
        $previousVendor = realpath($base.'/tarkov-api/vendor');
        if ($attempt === 2) { file_put_contents($previousVendor.'/.deploy-copy-probe', 'previous-release'); }
        [$status, $id, $output] = $automatic($attempt);
        if ($status !== 0) { throw new RuntimeException('Автоматический deploy завершился ошибкой: '.$output); }
        clearstatcache(true);
        if (!is_link($base.'/tarkov-api') || trim(file_get_contents($base.'/tarkov-api/REVISION')) !== $id) {
            throw new RuntimeException('Релиз не переключился.');
        }
        if ($attempt === 2) {
            $copiedProbe=$base.'/tarkov-api/vendor/.deploy-copy-probe';
            if (!is_file($copiedProbe) || fileinode($copiedProbe) === fileinode($previousVendor.'/.deploy-copy-probe')) {
                throw new RuntimeException('Зависимости не скопированы независимо от предыдущего релиза.');
            }
            file_put_contents($copiedProbe,'new-release');
            if (file_get_contents($previousVendor.'/.deploy-copy-probe') !== 'previous-release') { throw new RuntimeException('Новый vendor изменил работающий релиз.'); }
        }
        if (file_get_contents($base.'/tarkov-api/.env') !== $before ||
            file_get_contents($base.'/tarkov-api/storage/logs/preserve.txt') !== 'keep-storage' ||
            (int) $pdo->query("SELECT count(*) FROM users WHERE id='keep-this-user'")->fetchColumn() !== 1) {
            throw new RuntimeException('Автоматическое обновление потеряло приватные данные.');
        }
    }
    $previous = readlink($base.'/tarkov-api');
    [$status] = $automatic(3, true);
    clearstatcache(true);
    if ($status === 0 || readlink($base.'/tarkov-api') !== $previous || file_get_contents($base.'/tarkov-api/.env') !== $before) {
        throw new RuntimeException('Ошибка HTTP-проверки не вернула предыдущий код.');
    }
    // Очистка не должна проходить через storage/.env симлинки в удаляемом релизе.
    $old = $base.'/tarkov-releases/'.str_repeat('b', 40).'-1-1';
    mkdir($old);
    symlink($base.'/tarkov-shared/storage', $old.'/storage');
    symlink($base.'/tarkov-shared/.env', $old.'/.env');
    touch($old, 1);
    $run([PHP_BINARY, $root.'/deployment/alwaysdata/prune-releases.php', $base.'/tarkov-releases', $previous, $previous]);
    if (is_dir($old) || file_get_contents($base.'/tarkov-shared/.env') !== $before ||
        file_get_contents($base.'/tarkov-shared/storage/logs/preserve.txt') !== 'keep-storage') {
        throw new RuntimeException('Очистка старого кода затронула общие данные.');
    }
    // Команда ежедневного бэкапа доступна и без dev-пакетов в опубликованном API.
    $run([PHP_BINARY, $base.'/tarkov-api/artisan', 'wiki:backup', '--no-interaction']);
    $run([PHP_BINARY, $base.'/tarkov-api/artisan', 'wiki:backup', '--no-interaction']);
    if (count(glob($database.'.backup-daily-*')) !== 1) { throw new RuntimeException('Повтор daily создал лишнюю копию.'); }
    // Крупный каталог Orchid сокращает число копий по общему бюджету 100 МиБ.
    $deployCopies = count(glob($database.'.backup-????????-??????-??????'));
    // Новая daily-копия может занять весь бюджет и вытеснить все deploy-копии.
    if ($deployCopies > 3) { throw new RuntimeException('Не ограничены копии перед деплоем.'); }
    $catalog = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_filter(explode("\n", trim($run([PHP_BINARY, $base.'/tarkov-api/artisan', 'wiki:restore', '--list'])))));
    if (array_sum(array_column($catalog, 'bytes')) > 100 * 1024 * 1024 || array_filter($catalog, fn (array $copy): bool => !$copy['valid'])) {
        throw new RuntimeException('Не подтверждены лимит объёма и целостность резервных копий.');
    }
    $selected = array_values(array_filter($catalog, fn (array $copy): bool => str_contains($copy['name'], '.backup-daily-')))[0];
    $pdo->prepare('INSERT INTO users(id, username, password_hash, created_at) VALUES (?, ?, NULL, ?)')->execute(['after-snapshot', 'after-snapshot', time()]);
    $arguments = ['--backup='.$selected['name'], '--sha256='.$selected['sha256']];
    $run([PHP_BINARY, $base.'/tarkov-api/artisan', 'wiki:restore', '--check', ...$arguments]);
    if ((int) $pdo->query("SELECT count(*) FROM users WHERE id='after-snapshot'")->fetchColumn() !== 1) { throw new RuntimeException('Проверка изменила текущие данные.'); }
    $output = $run([PHP_BINARY, $base.'/tarkov-api/artisan', 'wiki:restore', '--confirm=RESTORE', ...$arguments]);
    if (!preg_match('/сохранена: (\S+)/u', $output, $match)) { throw new RuntimeException('Нет имени аварийной копии.'); }
    $safety = new PDO('sqlite:'.dirname($database).'/'.$match[1]);
    if ((int) $safety->query("SELECT count(*) FROM users WHERE id='after-snapshot'")->fetchColumn() !== 1 ||
        (int) $pdo->query("SELECT count(*) FROM users WHERE id='after-snapshot'")->fetchColumn() !== 0 ||
        (int) $pdo->query("SELECT count(*) FROM users WHERE id='keep-this-user'")->fetchColumn() !== 1 ||
        file_get_contents($base.'/tarkov-api/.env') !== $before || file_exists($database.'.restoring')) {
        throw new RuntimeException('Не подтверждены восстановление, аварийная копия или снятие защиты API.');
    }
    echo "PASS: production list/check/restore, неизменный .env, сохранение текущего WAL перед восстановлением.\n";
    echo "PASS: автоматические релизы, сохранение .env/storage/SQLite, HTTP-откат, очистка кода и daily/deploy retention.\n";
} finally {
    proc_terminate($server); proc_close($server);
}
