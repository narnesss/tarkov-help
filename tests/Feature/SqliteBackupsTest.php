<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\SqliteBackups;
use PDO;
use RuntimeException;
use Tests\TestCase;

/** Проверки настоящих WAL-снимков, лимитов диска и границ удаления. */
final class SqliteBackupsTest extends TestCase
{
    private string $directory;
    private string $database;
    private ?PDO $writer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/tarkov-backups-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->database = $this->directory.'/wiki.sqlite';
        $this->writer = new PDO('sqlite:'.$this->database);
        $this->writer->exec('PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0; CREATE TABLE progress (id INTEGER PRIMARY KEY, value TEXT); INSERT INTO progress VALUES (1, \'saved\')');
    }

    protected function tearDown(): void
    {
        $this->writer = null;
        foreach (new \FilesystemIterator($this->directory) as $entry) { unlink($entry->getPathname()); }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_daily_snapshot_includes_committed_wal_and_is_reused_within_a_day(): void
    {
        $this->assertFileExists($this->database.'-wal');
        $service = new SqliteBackups($this->database);
        $first = $service->create();
        $copy = new PDO('sqlite:'.$first['path']);
        $this->assertSame('saved', $copy->query('SELECT value FROM progress WHERE id=1')->fetchColumn());
        $this->assertSame('ok', $copy->query('PRAGMA quick_check')->fetchColumn());
        $this->assertSame(0600, fileperms($first['path']) & 0777);
        $this->writer->exec("INSERT INTO progress VALUES (2, 'newer')");
        $second = $service->create();
        $this->assertFalse($second['created']);
        $this->assertSame($first['path'], $second['path']);
        $this->assertSame(1, $second['count']);
        $this->assertSame(2, (int) $this->writer->query('SELECT count(*) FROM progress')->fetchColumn());
    }

    public function test_retention_separates_daily_and_legacy_deploy_copies_and_never_follows_links(): void
    {
        $template = (new SqliteBackups($this->database))->create('deploy')['path'];
        for ($i = 1; $i <= 12; $i++) {
            copy($template, $this->database.'.backup-daily-202501'.sprintf('%02d', $i).'-000000-aaaaaa');
            copy($template, $this->database.'.backup-202501'.sprintf('%02d', $i).'-000000-bbbbbb');
        }
        $foreign = $this->directory.'/other.sqlite.backup-20200101-000000-aaaaaa';
        file_put_contents($foreign, 'unrelated');
        file_put_contents($this->directory.'/.env', 'do-not-delete');
        $link = $this->database.'.backup-20200101-000000-cccccc';
        symlink($foreign, $link);
        $partial = $this->database.'.backup-daily-20200101-000000-dddddd.partial';
        file_put_contents($partial, 'interrupted'); touch($partial, time() - 172800);
        $result = (new SqliteBackups($this->database))->create();
        $this->assertSame(10, $result['count']);
        $this->assertCount(7, glob($this->database.'.backup-daily-????????-??????-??????'));
        $this->assertSame('unrelated', file_get_contents($foreign));
        $this->assertSame('do-not-delete', file_get_contents($this->directory.'/.env'));
        $this->assertTrue(is_link($link));
        $this->assertFileDoesNotExist($partial);
        $this->assertSame('saved', $this->writer->query('SELECT value FROM progress')->fetchColumn());
    }

    public function test_total_byte_budget_keeps_the_new_verified_copy(): void
    {
        $first = (new SqliteBackups($this->database))->create('deploy');
        for ($i = 1; $i <= 6; $i++) { copy($first['path'], $this->database.'.backup-2025010'.$i.'-000000-eeeeee'); }
        $budget = filesize($first['path']) * 2;
        $result = (new SqliteBackups($this->database, maxBytes: $budget))->create();
        $this->assertSame(2, $result['count']);
        $this->assertLessThanOrEqual($budget, $result['bytes']);
        $this->assertFileExists($result['path']);
        $this->assertFileExists($first['path']);
    }

    public function test_failed_backup_does_not_delete_previous_copies_or_create_missing_database(): void
    {
        $first = (new SqliteBackups($this->database))->create('deploy');
        try {
            (new SqliteBackups($this->database, maxBytes: 1))->create();
            $this->fail('Ожидался отказ из-за бюджета');
        } catch (RuntimeException $error) { $this->assertStringContainsString('бюджет', $error->getMessage()); }
        $this->assertFileExists($first['path']);
        $this->assertCount(1, glob($this->database.'.backup-????????-??????-??????'));
        $missing = $this->directory.'/missing.sqlite';
        try { new SqliteBackups($missing); $this->fail('Не должна создаваться новая БД'); }
        catch (RuntimeException) { $this->assertFileDoesNotExist($missing); }
    }

    public function test_artisan_uses_configured_database_and_policy(): void
    {
        config(['database.connections.sqlite.database' => $this->database, 'backup.daily_keep' => 2]);
        $this->artisan('wiki:backup')->expectsOutputToContain('Создана копия:')->assertSuccessful();
        $this->artisan('wiki:backup')->expectsOutputToContain('Копия за сегодня уже есть:')->assertSuccessful();
    }

    public function test_empty_daily_file_is_replaced_only_after_a_valid_snapshot(): void
    {
        $empty = $this->database.'.backup-daily-'.gmdate('Ymd-His').'-ffffff';
        touch($empty);
        $result = (new SqliteBackups($this->database))->create();
        $this->assertTrue($result['created']);
        $copy = new PDO('sqlite:'.$result['path']);
        $this->assertSame('saved', $copy->query('SELECT value FROM progress')->fetchColumn());
        $this->assertFileDoesNotExist($empty);
        $this->assertSame(1, $result['count']);
    }
}
