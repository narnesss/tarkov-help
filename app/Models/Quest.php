<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Orchid\Screen\AsSource;
use App\Services\ContentFiles;

/** ID заданий и целей остаются прежними для совместимости личного прогресса. */
final class Quest extends Model
{
    use AsSource;
    protected $guarded = ['id'];
    protected $casts = ['published' => 'boolean'];
    protected static function booted(): void
    { static::saved(fn (self $quest) => app(\App\Services\ReferenceIndexer::class)->quest($quest)); }

    public function getPayloadAttribute(?string $value): array
    { return $value === null ? [] : json_decode($this->contentJson($value), true, 128, JSON_THROW_ON_ERROR); }

    public function setPayloadAttribute(array $value): void
    { $this->attributes['payload'] = ContentFiles::forDatabase($this->getConnection()->getDatabaseName())->put($value); }

    public function json(): string { return $this->contentJson($this->getRawOriginal('payload')); }
    public function revision(): string { return hash('sha256', (string) $this->getRawOriginal('payload')); }

    private function contentJson(string $value): string
    {
        return str_starts_with($value, '@file:')
            ? ContentFiles::forDatabase($this->getConnection()->getDatabaseName())->json($value) : $value;
    }
}
