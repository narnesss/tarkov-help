<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;

/** Неизменяемые версии контента вне релизов: SQL хранит только ссылку на файл. */
final class ContentFiles
{
    public function __construct(private readonly string $directory) {}

    public static function forDatabase(string $database): self
    {
        $container=\Illuminate\Container\Container::getInstance();
        if($container->bound('config')&&($directory=$container->make('config')->get('content.directory'))){return new self($directory);}
        if($database!==':memory:'&&!str_contains($database,'/')&&!str_contains($database,'\\')){
            throw new RuntimeException('Для MySQL задайте постоянный CONTENT_DIRECTORY вне каталога релиза.');
        }
        return new self($database === ':memory:'
            ? sys_get_temp_dir().'/wiki-content-tests-'.getmypid()
            : dirname($database).'/content');
    }

    public function put(array $content): string
    {
        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json); $reference = '@file:'.$hash; $path = $this->path($reference);
        if (is_file($path)) { $this->json($reference); return $reference; }
        $mask = umask(0077); $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
                throw new RuntimeException('Не удалось создать каталог контента.');
            }
            if (file_put_contents($temporary, gzencode($json, 6), LOCK_EX) === false || !rename($temporary, $path)) {
                throw new RuntimeException('Не удалось сохранить файл контента.');
            }
        } finally { umask($mask); if (is_file($temporary)) { unlink($temporary); } }
        return $reference;
    }

    public function json(string $reference): string
    {
        $path = $this->path($reference);
        if (!is_file($path) || is_link($path)) { throw new RuntimeException('Отсутствует файл контента: '.$reference); }
        $json = @gzdecode(file_get_contents($path));
        if ($json === false || !hash_equals(substr($reference, 6), hash('sha256', $json))) {
            throw new RuntimeException('Файл контента повреждён: '.$reference);
        }
        return $json;
    }

    public function read(string $reference): array
    { return json_decode($this->json($reference), true, 128, JSON_THROW_ON_ERROR); }

    private function path(string $reference): string
    {
        if (!preg_match('/^@file:[a-f0-9]{64}$/D', $reference)) { throw new RuntimeException('Недопустимая ссылка контента.'); }
        $hash = substr($reference, 6);
        return $this->directory.'/'.substr($hash, 0, 2).'/'.$hash.'.json.gz';
    }
}
