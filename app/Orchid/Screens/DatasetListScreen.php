<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Models\ContentDataset;
use Orchid\Screen\{Screen, TD};
use Orchid\Screen\Actions\Link;
use Orchid\Support\Facades\Layout;

/** Редактор остальных справочников сайта. */
final class DatasetListScreen extends Screen
{
    public function name(): string { return 'Данные сайта'; }
    public function description(): string { return 'Карты, предметы, справочники и вспомогательные данные. Квесты редактируются в разделе «Квесты».'; }
    public function permission(): array { return ['wiki.content']; }
    public function query(): array
    { return ['datasets' => ContentDataset::orderBy('path')->paginate(30)]; }
    public function layout(): array
    {
        return [Layout::table('datasets', [
            TD::make('path', 'Набор данных')->render(fn (ContentDataset $dataset) => Link::make($dataset->path)->route('platform.datasets.edit', $dataset)),
            TD::make('updated_at', 'Обновлён'),
        ])];
    }
}
