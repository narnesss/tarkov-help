<?php
declare(strict_types=1);

// Проверяет production-настройки и сохраняет существующие ключ/БД при обновлении.
try {
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
    // Перед миграциями используем тот же lock/retention, что и ежедневное задание.
    $pdo = new PDO('sqlite:'.$database);
    $tables = (int) $pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
    if ($tables > 0) {
        $service = new \App\Services\SqliteBackups($database, (int) config('backup.daily_keep'),
            (int) config('backup.deploy_keep'), (int) config('backup.max_bytes'));
        $result = $service->create('deploy');
        echo 'Создана копия перед миграциями: '.$result['path'].PHP_EOL;
    }
} catch (\Throwable $error) {
    // Laravel exception handler в standalone PHP может завершиться с кодом 0.
    fwrite(STDERR, 'Подготовка API остановлена: '.$error->getMessage().PHP_EOL);
    exit(1);
}
