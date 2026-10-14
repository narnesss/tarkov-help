<?php
declare(strict_types=1);

// Composer is optional on shared hosting: the application has no runtime packages.
spl_autoload_register(static function (string $class): void {
    $prefix = 'EftWiki\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) { require $path; }
    }
});
