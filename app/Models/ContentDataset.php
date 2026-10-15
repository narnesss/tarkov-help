<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Orchid\Screen\AsSource;
use App\Services\ContentFiles;

/** Публичный набор данных в файле; в SQLite остаются путь и ссылка на версию. */
final class ContentDataset extends Model
{
    use AsSource;
    protected $guarded = ['id'];
    protected static function booted(): void
    { static::saved(fn (self $dataset) => app(\App\Services\ReferenceIndexer::class)->dataset($dataset)); }
    public function revision(): string { return hash('sha256', (string) $this->getRawOriginal('payload')); }

    public function content(): array
    {
        return json_decode($this->json(), true, 128, JSON_THROW_ON_ERROR);
    }

    public function json(): string
    {
        return str_starts_with($this->payload, '@file:')
            ? ContentFiles::forDatabase($this->getConnection()->getDatabaseName())->json($this->payload)
            : gzdecode(base64_decode($this->payload, true));
    }

    public function setContent(array $content): void
    {
        $this->payload = ContentFiles::forDatabase($this->getConnection()->getDatabaseName())->put($content);
    }
}
