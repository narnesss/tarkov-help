<?php
declare(strict_types=1);

// Проверяет production-настройки и сохраняет существующие ключ/БД при обновлении.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.env') !== 'production' || config('app.debug') || !config('api.only') || !config('api.proxy_required')) {
    throw new RuntimeException('Нужны production, APP_DEBUG=false, API_ONLY=true и API_PROXY_REQUIRED=true.');
}
$origin = parse_url((string) config('app.url'));
if (($origin['scheme'] ?? '') !== 'https' || empty($origin['host']) || !empty($origin['query']) || !empty($origin['fragment']) || !empty($origin['user']) || !empty($origin['pass']) || !in_array($origin['path'] ?? '', ['', '/'], true)) {
    throw new RuntimeException('APP_URL должен быть HTTPS origin публичного сайта Cloudflare.');
}
if (strlen((string) config('api.proxy_token')) < 32) { throw new RuntimeException('Сначала задайте API_PROXY_TOKEN длиной не менее 32 символов.'); }
if (!config('app.key')) { $app->make(\Illuminate\Contracts\Console\Kernel::class)->call('key:generate', ['--force' => true]); }
if (config('database.default') !== 'sqlite') { throw new RuntimeException('Этот деплой подготовлен для SQLite.'); }
$database = (string) config('database.connections.sqlite.database');
if (!str_starts_with($database, '/') || str_starts_with($database, realpath(__DIR__.'/../..').'/')) {
    throw new RuntimeException('DB_DATABASE должен указывать на постоянный абсолютный путь вне каталога релиза.');
}
$releaseBase = getenv('ALWAYSDATA_RELEASE_BASE');
if ($releaseBase !== false) {
    $parent = realpath(dirname($database));
    if ($parent === false) { throw new RuntimeException('Для обновления каталог существующей SQLite должен быть доступен.'); }
    $resolved = $parent.'/'.basename($database);
    foreach (['tarkov-api', 'tarkov-releases', 'tarkov-api-before-releases'] as $directory) {
        $code = realpath($releaseBase.'/'.$directory) ?: $releaseBase.'/'.$directory;
        if (str_starts_with($resolved, $code.'/')) { throw new RuntimeException('SQLite должна быть вне всех каталогов кода, включая первоначальный API.'); }
    }
    if (!is_file($database)) { throw new RuntimeException('Автоматическое обновление не создаёт отсутствующую production-БД. Проверьте DB_DATABASE.'); }
}
if (!is_dir(dirname($database)) && !mkdir(dirname($database), 0700, true)) { throw new RuntimeException('Не удалось создать каталог SQLite.'); }
if (!is_file($database) && !touch($database)) { throw new RuntimeException('Не удалось создать SQLite.'); }
chmod($database, 0600);
// VACUUM INTO согласованно копирует активную WAL-БД; обычный cp может потерять записи.
$pdo = new PDO('sqlite:'.$database);
$tables = (int) $pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
if ($tables > 0) {
    $destination = $database.'.backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
    $pdo->exec('VACUUM INTO '.$pdo->quote($destination));
    chmod($destination, 0600);
    echo "Создана резервная копия SQLite: $destination\n";
}
