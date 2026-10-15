<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Services\WikiSchema;
use Orchid\Screen\{Screen,TD};
use Orchid\Screen\Actions\{Link,Button};
use Orchid\Screen\Fields\{Input,CheckBox};
use Orchid\Support\Facades\Layout;

/** Список сущностей и связей с поиском, без отображения технических ключей. */
final class WikiListScreen extends Screen
{
    protected string $type='items';
    public function name(): string { return WikiSchema::TYPES[$this->type]; }
    public function permission(): array { return ['wiki.content']; }
    public function query(string $type): array
    {
        $this->type=$type;$schema=app(WikiSchema::class);$query=$schema->query($type);
        $search=WikiSchema::search((string)request()->query('q',''));$query->when($search!=='',fn($q)=>$q->whereRaw('instr(search_text,?)>0',[$search]));
        $context=self::context($type);if($context){$query->where($context['field'],$context['value']);}
        if(!request()->boolean('archived')){$query->where('archived',0);}
        return ['records'=>$query->orderBy('display_name')->paginate(30)->withQueryString()];
    }
    public static function context(string $type): ?array
    {
        $field=(string)request()->query('filter','');if($field===''){return null;}
        $foreign=app(WikiSchema::class)->foreigns($type)[$field]??null;abort_unless($foreign,404);
        $parent=app(WikiSchema::class)->query($foreign['type'])->where('rowid',request()->query('parent'))->firstOrFail();
        return ['field'=>$field,'value'=>$parent->{$foreign['column']},'label'=>$parent->display_name];
    }
    public function commandBar(): array
    { return [Link::make('Создать')->route('platform.wiki.create',['type'=>$this->type,...request()->only(['filter','parent'])]),Link::make('Все каталоги')->route('platform.wiki')]; }
    public function layout(): array
    {
        $type=$this->type;$context=self::context($type);
        return [Layout::rows([Input::make('search')->title('Поиск по названию')->value(request()->query('q','')),
            CheckBox::make('show_archived')->title('Показать архив')->value((int)request()->boolean('archived'))->sendTrueOrFalse(),
            Button::make('Найти')->method('search',request()->only(['filter','parent']))])->title($context['label']??'Поиск'),Layout::table('records',[
            TD::make('display_name','Запись')->render(fn($row)=>Link::make($row->display_name)->route('platform.wiki.edit',['type'=>$type,'record'=>$row->rowid,...request()->only(['filter','parent'])])),
            TD::make('archived','Состояние')->render(fn($row)=>$row->archived?'В архиве':'Активна'),
        ])];
    }
    public function search(\Illuminate\Http\Request $request,string $type)
    { $data=$request->validate(['search'=>['nullable','string','max:255'],'show_archived'=>['nullable','boolean']]);return redirect()->route('platform.wiki.list',['type'=>$type,'q'=>$data['search']??'','archived'=>$data['show_archived']??0,...$request->only(['filter','parent'])]); }
}
