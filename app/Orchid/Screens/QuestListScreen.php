<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Models\Quest;
use App\Services\TraderCatalog;
use Orchid\Screen\Screen;
use Orchid\Screen\TD;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\{Input, Select};
use Orchid\Support\Facades\Layout;

/** Каталог квестов с поиском и выбором режима. */
final class QuestListScreen extends Screen
{
    public function name(): string { return 'Квесты'; }
    public function permission(): array { return ['wiki.content']; }

    public function query(): array
    {
        $search = request()->query('search', ''); $mode = request()->query('mode', '');
        return ['search' => $search, 'mode' => $mode, 'quests' => Quest::query()
            ->when(is_string($search) && $search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->when(in_array($mode, ['pve', 'regular'], true), fn ($q) => $q->where('mode', $mode))
            ->orderBy('name')->paginate(30)->withQueryString()];
    }

    public function commandBar(): array
    { return [Link::make('Добавить квест')->icon('bs.plus')->route('platform.quests.create')]; }

    public function layout(): array
    {
        $traders = app(TraderCatalog::class);
        return [Layout::rows([
            Input::make('search')->title('Поиск')->type('search'),
            Select::make('mode')->title('Режим')->options(['' => 'Все', 'pve' => 'PVE', 'regular' => 'PVP']),
            \Orchid\Screen\Actions\Button::make('Найти')->method('filter'),
        ]), Layout::table('quests', [
            TD::make('name', 'Название')->render(fn (Quest $quest) => Link::make($quest->name)->route('platform.quests.edit', $quest)),
            TD::make('mode', 'Режим')->render(fn (Quest $quest) => $quest->mode === 'pve' ? 'PVE' : 'PVP'),
            TD::make('trader_id', 'Торговец')->render(fn (Quest $quest) => e($traders->name($quest->trader_id))),
            TD::make('published', 'Публикация')->render(fn (Quest $quest) => $quest->published ? 'Опубликован' : 'Черновик'),
        ])];
    }

    public function filter(\Illuminate\Http\Request $request)
    { return redirect()->route('platform.quests', $request->only(['search', 'mode'])); }
}
