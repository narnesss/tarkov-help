<?php
declare(strict_types=1);
namespace Tests;

/** Загружает настоящий Laravel kernel для HTTP/миграционных проверок. */
abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.example');
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }
}
