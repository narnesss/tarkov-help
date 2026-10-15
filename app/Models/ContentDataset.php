<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Orchid\Screen\AsSource;

/** Публичный набор данных; сжатие сохраняет квоту SQLite и резервных копий. */
final class ContentDataset extends Model
{
    use AsSource;
    protected $guarded = ['id'];

    public function content(): array
    {
        return json_decode($this->json(), true, 128, JSON_THROW_ON_ERROR);
    }

    public function json(): string { return gzdecode(base64_decode($this->payload, true)); }

    public function setContent(array $content): void
    {
        $this->payload = base64_encode(gzencode(json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 6));
    }
}
