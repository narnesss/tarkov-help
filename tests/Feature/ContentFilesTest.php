<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\{ContentFiles, SqliteBackups};
use Illuminate\Filesystem\Filesystem;
use PDO;
use RuntimeException;
use Tests\TestCase;

/** Проверяет версии файлов, восстановление контента и отказ при повреждении файла. */
final class ContentFilesTest extends TestCase
{
    private string $directory;
    protected function setUp(): void
    { parent::setUp();$this->directory = sys_get_temp_dir().'/wiki-content-fixture-'.bin2hex(random_bytes(12));mkdir($this->directory, 0700); }
    protected function tearDown(): void
    { (new Filesystem)->deleteDirectory($this->directory);parent::tearDown(); }

    public function test_snapshot_restores_old_file_revision_and_keeps_newer_version_available(): void
    {
        $database = $this->directory.'/wiki.sqlite';$pdo = new PDO('sqlite:'.$database);
        $pdo->exec('CREATE TABLE quests (id INTEGER PRIMARY KEY, payload TEXT)');
        $files = ContentFiles::forDatabase($database);$old = $files->put(['name' => 'До правки']);
        $pdo->prepare('INSERT INTO quests VALUES (1, ?)')->execute([$old]);
        $backups = new SqliteBackups($database);$copy = $backups->create('deploy')['path'];
        $new = $files->put(['name' => 'После правки']);$pdo->prepare('UPDATE quests SET payload=?')->execute([$new]);
        $backups->restore(basename($copy), hash_file('sha256', $copy), function () use ($pdo, $files): void {
            $this->assertSame('До правки', $files->read($pdo->query('SELECT payload FROM quests')->fetchColumn())['name']);
        });
        $this->assertSame('После правки', $files->read($new)['name']);
        $this->assertSame($old, $files->put(['name' => 'До правки']));
        $pdo = null;
    }

    public function test_missing_content_file_blocks_backup_verification_and_restore_before_database_changes(): void
    {
        $database = $this->directory.'/wiki.sqlite';$pdo = new PDO('sqlite:'.$database);
        $pdo->exec('CREATE TABLE quests (id INTEGER PRIMARY KEY, payload TEXT)');
        $files = ContentFiles::forDatabase($database);$reference = $files->put(['name' => 'Контент']);
        $pdo->prepare('INSERT INTO quests VALUES (1, ?)')->execute([$reference]);
        $backups = new SqliteBackups($database);$copy = $backups->create('deploy')['path'];
        $hash = substr($reference, 6);unlink($this->directory.'/content/'.substr($hash, 0, 2).'/'.$hash.'.json.gz');
        $this->assertFalse($backups->catalog()[0]['valid']);
        try { $backups->restore(basename($copy), hash_file('sha256', $copy), fn () => null);$this->fail('Отсутствующий контент принят'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('Отсутствует файл контента', $error->getMessage()); }
        $this->assertSame($reference, $pdo->query('SELECT payload FROM quests')->fetchColumn());
        $pdo = null;
    }
}
