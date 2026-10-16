<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Models\Quest;
use App\Services\{AdminInput,AdminNavigation,TraderCatalog};
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
    public function needPreventsAbandonment(): bool { return false; }

    public function query(): array
    {
        $search=AdminInput::search(request()->query('search',''));$mode=AdminNavigation::choice('mode',AdminNavigation::MODES,'pve');
        $section=AdminNavigation::choice('section',AdminNavigation::QUEST_SECTIONS,'all');
        $state=AdminNavigation::choice('state',['all'=>'Все','published'=>'Опубликованные','draft'=>'Черновики'],'all');
        $trader=AdminNavigation::choice('trader',['all'=>'Все торговцы',...app(TraderCatalog::class)->options()],'all');
        $query=Quest::query()->select(['id','quest_id','mode','name','trader_id','published'])->where('mode',$mode)
            ->when($search!=='',fn($q)=>$q->whereRaw('instr(name,?)>0',[$search]))
            ->when($state!=='all',fn($q)=>$q->where('published',$state==='published'))
            ->when($trader!=='all',fn($q)=>$q->where('trader_id',$trader));
        if($section!=='all'){$query->whereIn('id',function($q) use($section){$q->select('legacy_quest_id')->from('wiki_quests')->whereNotNull('legacy_quest_id');
            match($section){'story'=>$q->where('is_story',1),'standard'=>$q->where('is_story',0)->where('archived',0),'kappa'=>$q->where('kappa_required',1),default=>$q->where('archived',1)};});}
        $base=compact('search','mode','section','state','trader');
        return [...$base,'quests'=>$query->orderBy('name')->orderBy('id')->paginate(30)->withQueryString(),'catalogTabs'=>[
            'Режим'=>AdminNavigation::tabs('platform.quests',AdminNavigation::MODES,'mode',$mode,$base),
            'Подкатегория'=>AdminNavigation::tabs('platform.quests',AdminNavigation::QUEST_SECTIONS,'section',$section,$base)]];
    }

    public function commandBar(): array
    { return [Link::make('Добавить квест')->icon('bs.plus')->route('platform.quests.create',['mode'=>request()->query('mode','pve')]),
        Link::make('Цели, условия и награды')->route('platform.wiki',['group'=>'quests'])]; }

    public function layout(): array
    {
        $traders = app(TraderCatalog::class);
        return [Layout::view('platform.catalog-tabs'),Layout::rows([
            Input::make('mode')->type('hidden'),Input::make('section')->type('hidden'),
            Input::make('search')->title('Поиск')->type('search')->maxlength(255)->set('data-admin-search','1'),
            Select::make('trader')->title('Торговец')->options(['all'=>'Все торговцы',...$traders->options()]),
            Select::make('state')->title('Публикация')->options(['all'=>'Все','published'=>'Опубликованные','draft'=>'Черновики']),
            \Orchid\Screen\Actions\Button::make('Найти')->method('filter')->rawClick(),
        ]),Layout::view('platform.content-validation'), Layout::table('quests', [
            TD::make('name', 'Название')->render(fn (Quest $quest) => Link::make($quest->name)->route('platform.quests.edit', $quest)),
            TD::make('mode', 'Режим')->render(fn (Quest $quest) => $quest->mode === 'pve' ? 'PVE' : 'PVP'),
            TD::make('trader_id', 'Торговец')->render(fn (Quest $quest) => e($traders->name($quest->trader_id))),
            TD::make('published', 'Публикация')->render(fn (Quest $quest) => $quest->published ? 'Опубликован' : 'Черновик'),
        ])];
    }

    public function filter(\Illuminate\Http\Request $request)
    { $data=$request->validate(['mode'=>['required','string',\Illuminate\Validation\Rule::in(array_keys(AdminNavigation::MODES))],
        'section'=>['required','string',\Illuminate\Validation\Rule::in(array_keys(AdminNavigation::QUEST_SECTIONS))],
        'state'=>['required','string',\Illuminate\Validation\Rule::in(['all','published','draft'])],
        'trader'=>['required','string',\Illuminate\Validation\Rule::in(['all',...array_keys(app(TraderCatalog::class)->options())])]]);
        return redirect()->route('platform.quests',[...$data,'search'=>AdminInput::search($request->input('search'))]); }
}
