<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\ContentFiles;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Моделирует отказы файловой публикации до смены SQL-ссылки. */
final class ContentFileWritesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();$this->directory = sys_get_temp_dir().'/wiki-write-fixture-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    { (new Filesystem)->deleteDirectory($this->directory);parent::tearDown(); }

    public static function failedWrites(): array
    { return [['short'], ['false'], ['corrupt-gzip'], ['wrong-hash'], ['exception']]; }

    #[DataProvider('failedWrites')]
    public function test_failed_or_corrupted_write_cannot_publish_and_keeps_previous_version(string $failure): void
    {
        $files = new ContentFiles($this->directory);$old = $files->put(['name' => 'Старая версия']);
        $io = $this->getMockBuilder(Filesystem::class)->onlyMethods(['put', 'move'])->getMock();
        $io->expects($this->never())->method('move');
        $io->expects($this->once())->method('put')->willReturnCallback(static function ($path, $gzip, $locked) use ($failure) {
            $bytes = match ($failure) {
                'short', 'false', 'exception' => substr($gzip, 0, 4),
                'wrong-hash' => gzencode('{"other":true}'), default => 'broken-gzip',
            };
            file_put_contents($path, $bytes);
            if ($failure === 'exception') { throw new RuntimeException('Тестовый обрыв записи'); }
            return match ($failure) { 'short' => strlen($bytes), 'false' => false, default => strlen($gzip) };
        });
        $mask = umask();
        try { (new ContentFiles($this->directory, $io))->put(['name' => 'Новая версия']);$this->fail('Повреждённый файл опубликован'); }
        catch (RuntimeException $error) { $this->assertNotSame('', $error->getMessage()); }
        $this->assertSame($mask, umask());
        $this->assertSame(['name' => 'Старая версия'], $files->read($old));
        $this->assertCount(1, (new Filesystem)->allFiles($this->directory));
    }

    public function test_rename_failure_removes_temporary_file_and_allows_retry(): void
    {
        $io = $this->getMockBuilder(Filesystem::class)->onlyMethods(['move'])->getMock();
        $io->expects($this->once())->method('move')->willReturn(false);
        try { (new ContentFiles($this->directory, $io))->put(['name' => 'Повтор']);$this->fail('Отказ rename принят'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('сохранить', $error->getMessage()); }
        $this->assertSame([], (new Filesystem)->allFiles($this->directory));
        $files = new ContentFiles($this->directory);$reference = $files->put(['name' => 'Повтор']);
        $this->assertSame(['name' => 'Повтор'], $files->read($reference));
        $this->assertSame($reference, $files->put(['name' => 'Повтор']));
    }

    public function test_existing_corrupt_version_is_rejected_instead_of_replaced(): void
    {
        $files = new ContentFiles($this->directory);$content = ['name' => 'Стабильная'];$reference = $files->put($content);
        $path = (new Filesystem)->allFiles($this->directory)[0]->getPathname();file_put_contents($path, 'broken');
        try { $files->put($content);$this->fail('Повреждение существующей версии скрыто'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('повреждён', $error->getMessage()); }
        $this->assertSame('broken', file_get_contents($path));$this->assertCount(1, (new Filesystem)->allFiles($this->directory));
        $this->expectException(RuntimeException::class);$files->json($reference);
    }
}
