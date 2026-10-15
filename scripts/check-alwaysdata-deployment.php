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
if (!preg_match('/^APP_KEY=base64:.+$/m', $before)) { throw new RuntimeException('APP_KEY не создан.'); }
$pdo = new PDO('sqlite:'.$database);
$pdo->prepare('INSERT INTO users(id, username, password_hash, created_at) VALUES (?, ?, NULL, ?)')->execute(['keep-this-user', 'deploy-probe', time()]);
$pdo = null;
$run(['sh', 'deployment/alwaysdata/deploy.sh']);
if (file_get_contents($package.'/.env') !== $before) { throw new RuntimeException('Обновление изменило APP_KEY или .env.'); }
$pdo = new PDO('sqlite:'.$database);
if ((int) $pdo->query("SELECT count(*) FROM users WHERE id='keep-this-user'")->fetchColumn() !== 1) { throw new RuntimeException('Обновление потеряло данные.'); }
$backups = glob($database.'.backup-*');
if (count($backups) !== 1) { throw new RuntimeException('Не создана резервная копия перед обновлением.'); }
$backup = new PDO('sqlite:'.$backups[0]);
if ((int) $backup->query("SELECT count(*) FROM users WHERE id='keep-this-user'")->fetchColumn() !== 1) { throw new RuntimeException('Резервная копия потеряла данные.'); }
$routes = json_decode($run([PHP_BINARY, 'artisan', 'route:list', '--json']), true, flags: JSON_THROW_ON_ERROR);
foreach ($routes as $route) {
    if (!in_array($route['uri'], ['up', 'api/{path?}'], true)) { throw new RuntimeException('API-пакет публикует лишний маршрут: '.$route['uri']); }
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
        [$status, $id, $output] = $automatic($attempt);
        if ($status !== 0) { throw new RuntimeException('Автоматический deploy завершился ошибкой: '.$output); }
        clearstatcache(true);
        if (!is_link($base.'/tarkov-api') || trim(file_get_contents($base.'/tarkov-api/REVISION')) !== $id) {
            throw new RuntimeException('Релиз не переключился.');
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
    echo "PASS: автоматические релизы, сохранение .env/storage/SQLite, HTTP-откат и безопасная очистка кода.\n";
} finally {
    proc_terminate($server); proc_close($server);
}
