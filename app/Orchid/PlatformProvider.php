<?php
declare(strict_types=1);
namespace App\Orchid;

use Orchid\Platform\Dashboard;
use Orchid\Platform\ItemPermission;
use Orchid\Platform\OrchidServiceProvider;
use Orchid\Screen\Actions\Menu;

/** Регистрирует редакторов, разделы и права доступа к контенту. */
final class PlatformProvider extends OrchidServiceProvider
{
    public function boot(Dashboard $dashboard): void
    {
        Dashboard::useModel(\Orchid\Platform\Models\User::class, \App\Models\User::class);
        parent::boot($dashboard);
    }

    public function menu(): array
    {
        return [
            Menu::make('Квесты')->icon('bs.journal-text')->route('platform.quests')->permission('wiki.content'),
            Menu::make('Данные сайта')->icon('bs.database')->route('platform.datasets')->permission('wiki.content'),
        ];
    }

    public function permissions(): array
    {
        return [ItemPermission::group('Tarkov Wiki')->addPermission('wiki.content', 'Редактирование данных сайта')];
    }
}
