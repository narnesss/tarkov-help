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
