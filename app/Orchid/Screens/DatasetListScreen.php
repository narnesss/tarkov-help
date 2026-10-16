<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Models\ContentDataset;
use App\Services\{AdminInput,AdminNavigation,DatasetCatalog};
use Orchid\Screen\{Screen, TD};
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\Input;
use Orchid\Support\Facades\Layout;

/** Редактор остальных справочников сайта. */
final class DatasetListScreen extends Screen
{
    public function name(): string { return 'Данные сайта'; }
    public function description(): string { return 'Выберите раздел и режим. Названия файлов и технические пути не нужны для заполнения данных.'; }
    public function permission(): array { return ['wiki.content']; }
    public function query(): array
    {
        $group=AdminNavigation::choice('group',DatasetCatalog::GROUPS,'all');$mode=AdminNavigation::choice('mode',['all'=>'Все режимы','common'=>'Общие',...AdminNavigation::MODES],'all');
        $search=AdminInput::search(request()->query('search',''));$rows=app(DatasetCatalog::class)->rows()->filter(fn($row)=>($group==='all'||$row->group===$group)
            &&($mode==='all'||$row->mode===['common'=>'Общие',...AdminNavigation::MODES][$mode])&&($search===''||mb_stripos($row->title,$search)!==false))->values();
        $base=compact('group','mode','search');$page=\Illuminate\Pagination\Paginator::resolveCurrentPage();
        return [...$base,'datasets'=>new \Illuminate\Pagination\LengthAwarePaginator($rows->forPage($page,30),$rows->count(),30,$page,['path'=>request()->url(),'query'=>request()->query()]),
            'catalogTabs'=>['Разделы'=>AdminNavigation::tabs('platform.datasets',DatasetCatalog::GROUPS,'group',$group,$base),
                'Режим'=>AdminNavigation::tabs('platform.datasets',['all'=>'Все режимы','common'=>'Общие',...AdminNavigation::MODES],'mode',$mode,$base)]];
    }
    public function layout(): array
    {
        return [Layout::view('platform.catalog-tabs'),Layout::view('platform.content-validation'),Layout::rows([
            Input::make('group')->type('hidden'),Input::make('mode')->type('hidden'),Input::make('search')->title('Поиск справочника')->maxlength(255)->set('data-admin-search','1'),
            \Orchid\Screen\Actions\Button::make('Найти')->method('search'),
        ]),Layout::table('datasets', [
            TD::make('title', 'Справочник')->render(fn ($row) => Link::make($row->title)->route('platform.datasets.edit',['dataset'=>$row->id,'section'=>$row->section])),
            TD::make('mode','Режим')->render(fn($row)=>e($row->mode)),
            TD::make('group','Раздел')->render(fn($row)=>e(DatasetCatalog::GROUPS[$row->group])),
            TD::make('updated_at', 'Обновлён')->render(fn($row)=>e((string)$row->updated_at)),
        ])];
    }
    public function search(\Illuminate\Http\Request $request)
    {
        $data=$request->validate(['group'=>['required','string',\Illuminate\Validation\Rule::in(array_keys(DatasetCatalog::GROUPS))],
            'mode'=>['required','string',\Illuminate\Validation\Rule::in(['all','common',...array_keys(AdminNavigation::MODES)])]]);
        return redirect()->route('platform.datasets',[...$data,'search'=>AdminInput::search($request->input('search'))]);
    }
}
