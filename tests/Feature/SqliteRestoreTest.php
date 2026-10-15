<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\{DatabaseAccess, SqliteBackups};
use PDO;
use RuntimeException;
use Tests\TestCase;

/** Настоящее восстановление WAL, аварийный откат и блокировка HTTP до замены данных. */
final class SqliteRestoreTest extends TestCase
{
    private string $directory;
    private string $database;
    private ?PDO $writer;
    private SqliteBackups $service;
    private string $name;
    private string $sha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/tarkov-restore-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->database = $this->directory.'/wiki.sqlite';
        $this->writer = new PDO('sqlite:'.$this->database);
        $this->writer->exec("PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0; CREATE TABLE progress (id INTEGER PRIMARY KEY, value TEXT); INSERT INTO progress VALUES (1, 'before')");
        $this->service = new SqliteBackups($this->database, deployKeep: 1);
        $path = $this->service->create('deploy')['path'];
        $this->name = basename($path); $this->sha = hash_file('sha256', $path);
        $this->writer->exec("INSERT INTO progress VALUES (2, 'after')");
    }

    protected function tearDown(): void
    {
        $this->writer = null;
        foreach (new \FilesystemIterator($this->directory) as $entry) { unlink($entry->getPathname()); }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_restores_selected_snapshot_and_preserves_current_wal_even_when_retention_removes_selected_name(): void
    {
        $inode = fileinode($this->database);
        $result = $this->service->restore($this->name, $this->sha, function (): void {
            $this->assertFileExists($this->database.'.restoring');
            $this->assertSame(1, (int) $this->writer->query('SELECT count(*) FROM progress')->fetchColumn());
        });
        $safety = new PDO('sqlite:'.$this->directory.'/'.$result['safety']);
        $this->assertSame(2, (int) $safety->query('SELECT count(*) FROM progress')->fetchColumn());
        $this->assertSame('after', $safety->query('SELECT value FROM progress WHERE id=2')->fetchColumn());
        $this->assertSame(1, (int) $this->writer->query('SELECT count(*) FROM progress')->fetchColumn());
        $this->assertSame($inode, fileinode($this->database));
        $this->assertFileDoesNotExist($this->directory.'/'.$this->name);
        $this->assertFileDoesNotExist($this->database.'.restoring');
        $this->assertSame('ok', $this->writer->query('PRAGMA quick_check')->fetchColumn());
    }

    public function test_failed_post_restore_validation_returns_current_database_and_reopens_api(): void
    {
        try {
            $this->service->restore($this->name, $this->sha, fn () => throw new RuntimeException('forced validation failure'));
            $this->fail('Должна сработать аварийная копия');
        } catch (RuntimeException $error) { $this->assertStringContainsString('forced validation failure', $error->getMessage()); }
        $this->assertSame(2, (int) $this->writer->query('SELECT count(*) FROM progress')->fetchColumn());
        $this->assertFileDoesNotExist($this->database.'.restoring');
        $this->assertCount(1, $this->service->catalog());
    }

    public function test_rejects_traversal_links_wrong_digest_and_changed_schema_before_any_current_database_write(): void
    {
        $link = $this->directory.'/wiki.sqlite.backup-20000101-000000-aaaaaa';
        symlink($this->directory.'/'.$this->name, $link);
        foreach ([['../'.$this->name, $this->sha], [basename($link), $this->sha], [$this->name, str_repeat('0', 64)]] as [$name, $sha]) {
            try { $this->service->restore($name, $sha, fn () => null); $this->fail('Недопустимый снимок принят'); }
            catch (RuntimeException) { $this->assertSame(2, (int) $this->writer->query('SELECT count(*) FROM progress')->fetchColumn()); }
        }
        $this->writer->exec('ALTER TABLE progress ADD COLUMN added TEXT');
        try { $this->service->restore($this->name, $this->sha, fn () => null); $this->fail('Несовместимая схема принята'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('несовместим', $error->getMessage()); }
        $this->assertFileDoesNotExist($this->database.'.restoring');
        $this->assertCount(1, $this->service->catalog());
    }

    public function test_http_is_private_503_while_exclusive_lock_or_recovery_marker_exists(): void
    {
        config(['database.connections.sqlite.database' => $this->database]);
        $gate = new DatabaseAccess($this->database);
        $lock = $gate->acquire(exclusive: true);
        $this->assertIsResource($lock);
        try {
            $this->getJson('/api/session')->assertStatus(503)->assertHeader('Retry-After', '60');
            $this->get('/up')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        file_put_contents($gate->marker(), 'interrupted');
        $this->getJson('/api/session')->assertStatus(503);
        try { $this->service->create(); $this->fail('Бэкап запущен во время аварийного восстановления'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('восстановления', $error->getMessage()); }
        unlink($gate->marker());
        $this->get('/up')->assertOk();
        $shared = $gate->acquire();
        try { $this->assertNull($gate->acquire(exclusive: true)); }
        finally { flock($shared, LOCK_UN); fclose($shared); }
    }

    public function test_list_check_and_missing_confirmation_do_not_replace_current_database(): void
    {
        config(['database.connections.sqlite.database' => $this->database]);
        $this->artisan('wiki:restore', ['--list' => true])->expectsOutputToContain($this->sha)->assertSuccessful();
        $this->artisan('wiki:restore', ['--check' => true, '--backup' => $this->name, '--sha256' => $this->sha])->assertSuccessful();
        $this->artisan('wiki:restore', ['--backup' => $this->name, '--sha256' => $this->sha])->assertFailed();
        $this->assertSame(2, (int) $this->writer->query('SELECT count(*) FROM progress')->fetchColumn());
    }
}
