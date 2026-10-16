<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Services\{AdminInput,AdminNavigation,ItemCatalog,WikiSchema};
use Orchid\Screen\{Screen,TD};
use Orchid\Screen\Actions\{Link,Button};
use Orchid\Screen\Fields\{Input,CheckBox,Select};
use Orchid\Support\Facades\Layout;

/** Список сущностей и связей с поиском, без отображения технических ключей. */
final class WikiListScreen extends Screen
{
    protected string $type='items';
    protected array $filters=[];
    public function name(): string { return WikiSchema::TYPES[$this->type]; }
    public function permission(): array { return ['wiki.content']; }
    public function needPreventsAbandonment(): bool { return false; }
    public function query(string $type): array
    {
        $this->type=$type;$schema=app(WikiSchema::class);$query=$schema->query($type);
        $search=WikiSchema::search(AdminInput::search(request()->query('q',''),'q'));$query->when($search!=='',fn($q)=>$q->whereRaw('instr(search_text,?)>0',[$search]));
        $context=self::context($type);if($context){$query->where($context['field'],$context['value']);}
        if(!request()->boolean('archived')){$query->where('archived',0);}
        $tabs=[];$this->filters=self::filters($type);$base=['type'=>$type,...request()->only(['q','filter','parent','archived']),...$this->filters];
        if(in_array($type,['items','keys'],true)){
            app(ItemCatalog::class)->filter($query,$type,$this->filters['item_group']);
            $options=$type==='keys'?['keys'=>'Ключи и ключ-карты','related'=>'Другие связанные предметы','all'=>'Все связанные записи']:ItemCatalog::GROUPS;
            $tabs['Тип предметов']=AdminNavigation::tabs('platform.wiki.list',$options,'item_group',$this->filters['item_group'],$base);
        }
        if($type==='quests'){$query->where('mode',$this->filters['mode']);$tabs['Режим']=AdminNavigation::tabs('platform.wiki.list',AdminNavigation::MODES,'mode',$this->filters['mode'],$base);}
        if($type==='map_points'){
            $documents=$this->filters['point_kind']==='documents';
            $query->where(function($q) use($documents){$q->where('source_kind',$documents?'document':'raid')->orWhere(function($q) use($documents){$q->where('source_kind','editor');$method=$documents?'whereExists':'whereNotExists';$q->{$method}(fn($sub)=>$sub->selectRaw('1')->from('wiki_point_documents')->whereColumn('wiki_point_documents.point_id','wiki_map_points.id')->where('wiki_point_documents.archived',0));});});
            if(!$documents){$query->whereIn('mode',[$this->filters['mode'],'all']);$tabs['Режим']=AdminNavigation::tabs('platform.wiki.list',AdminNavigation::MODES,'mode',$this->filters['mode'],$base);}
            if($this->filters['location']!==''){$query->where('location_id',$this->filters['location']);}
            $tabs=['Назначение точек'=>AdminNavigation::tabs('platform.wiki.list',['interactive'=>'Интерактивные карты','documents'=>'Документы'],'point_kind',$this->filters['point_kind'],$base),...$tabs];
        }
        return [...$this->filters,'records'=>$query->orderBy('display_name')->orderBy(app(WikiSchema::class)->identity($type))->paginate(30)->withQueryString(),'catalogTabs'=>$tabs];
    }
    public static function filters(string $type): array
    {
        $filters=[];
        if(in_array($type,['items','keys'],true)){$filters['item_group']=AdminNavigation::choice('item_group',$type==='keys'?['keys'=>'','related'=>'','all'=>'']:ItemCatalog::GROUPS,$type==='keys'?'keys':'all');}
        if(in_array($type,['quests','map_points'],true)){$filters['mode']=AdminNavigation::choice('mode',AdminNavigation::MODES,'pve');}
        if($type==='map_points'){
            $filters['point_kind']=AdminNavigation::choice('point_kind',['interactive'=>'','documents'=>''],'interactive');
            $value=request()->query('location','');$data=\Illuminate\Support\Facades\Validator::make(['location'=>$value],['location'=>['bail','nullable','integer',\Illuminate\Validation\Rule::exists('wiki_locations','id')]],AdminInput::messages())->validate();
            $filters['location']=$data['location']??'';
        }
        return $filters;
    }
    public static function parameters(string $type): array { return [...request()->only(['filter','parent']),...self::filters($type)]; }
    public static function context(string $type): ?array
    {
        $data=\Illuminate\Support\Facades\Validator::make(request()->query(),['filter'=>['sometimes','string','max:100'],'parent'=>['sometimes','integer','min:1'],'archived'=>['sometimes','boolean']])->validate();
        $field=$data['filter']??'';if($field===''){return null;}
        $foreign=app(WikiSchema::class)->foreigns($type)[$field]??null;abort_unless($foreign,404);
        abort_unless(isset($data['parent']),404);$parent=app(WikiSchema::class)->record($foreign['type'],$data['parent'])->firstOrFail();
        return ['field'=>$field,'value'=>$parent->{$foreign['column']},'label'=>$parent->display_name];
    }
    public function commandBar(): array
    { return [Link::make('Создать')->route('platform.wiki.create',['type'=>$this->type,...self::parameters($this->type)]),Link::make('Все каталоги')->route('platform.wiki')]; }
    public function layout(): array
    {
        $type=$this->type;$context=self::context($type);
        $extra=[];foreach($this->filters as $key=>$value){$extra[]=$key==='location'?Select::make('location')->title('Локация')->options([''=>'Все локации']+\Illuminate\Support\Facades\DB::table('wiki_locations')->where('archived',0)->orderBy('name')->pluck('name','id')->all()):Input::make($key)->type('hidden');}
        return [Layout::view('platform.catalog-tabs'),Layout::view('platform.content-validation'),Layout::rows([Input::make('search')->title('Поиск по названию')->maxlength(255)->set('data-admin-search','1')->value(request()->query('q','')),...$extra,
            CheckBox::make('show_archived')->title('Показать архив')->value((int)request()->boolean('archived'))->sendTrueOrFalse(),
            Button::make('Найти')->method('search',self::parameters($type))->rawClick()])->title($context['label']??'Поиск'),Layout::table('records',[
            TD::make('display_name','Запись')->render(fn($row)=>Link::make($row->display_name)->route('platform.wiki.edit',['type'=>$type,'record'=>$row->rowid,...self::parameters($type)])),
            TD::make('archived','Состояние')->render(fn($row)=>$row->archived?'В архиве':'Активна'),
        ])];
    }
    public function search(\Illuminate\Http\Request $request,string $type)
    { self::context($type);$filters=self::filters($type);$data=$request->validate(['show_archived'=>['nullable','boolean'],'location'=>['bail','nullable','integer',\Illuminate\Validation\Rule::exists('wiki_locations','id')]]);if($type==='map_points'){$filters['location']=$data['location']??'';}$search=AdminInput::search($request->input('search'));return redirect()->route('platform.wiki.list',['type'=>$type,'q'=>$search,'archived'=>$data['show_archived']??0,...$request->only(['filter','parent']),...$filters]); }
}
