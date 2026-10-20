<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/** Визуальное редактирование точек всех этажей рейдовых карт. */
final class MapEditorScreen extends Screen
{
    public function name(): string { return 'Редактор карт'; }
    public function description(): string { return 'Выберите карту, режим и этаж. Добавляйте точки нажатием на карту или перемещайте существующие.'; }
    public function permission(): array { return ['wiki.content']; }
    public function query(): array { return []; }
    public function commandBar(): array { return []; }
    public function layout(): array { return [Layout::view('platform.map-editor')]; }
}
