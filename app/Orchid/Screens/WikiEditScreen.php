<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Models\Wiki\Options;
use App\Services\{AdminInput,WikiSchema,WikiEditor};
use Illuminate\Http\Request;
use Orchid\Screen\Screen;
use Orchid\Screen\Fields\{Input,TextArea,Select,CheckBox,Relation};
use Orchid\Screen\Actions\{Link,Button};
use Orchid\Support\Facades\{Layout,Toast};

/** Редактирует предметные строки и FK через названия выбранных записей. */
final class WikiEditScreen extends Screen
{
    protected string $type='items';
    protected ?int $recordId=null;
    protected array $values=[];
    public function name(): string { return ($this->recordId===null?'Новая запись: ':'Редактирование: ').WikiSchema::TYPES[$this->type]; }
    public function permission(): array { return ['wiki.content']; }
    public function query(string $type,?int $record=null): array
    {
        $this->type=$type;$this->recordId=$record;$schema=app(WikiSchema::class);
        $row=$record===null?null:$schema->record($type,$record)->firstOrFail();$values=[];
        foreach($schema->fields($type) as $field=>$column){
            $value=$row?->$field??($column->dflt_value===null?null:trim($column->dflt_value,"'\""));
            if(isset($schema->foreigns($type)[$field])&&$value!==null){$foreign=$schema->foreigns($type)[$field];$value=$schema->query($foreign['type'])->where($foreign['column'],$value)->value('rowid');}
            $values[$field]=$value;
        }
        if(($context=WikiListScreen::context($type))&&$record===null){$values[$context['field']]=request()->query('parent');}
        $filters=WikiListScreen::filters($type);if($record===null&&$type==='quests'){$values['mode']=$filters['mode'];}
        $this->values=$values;
        return ['row'=>[...$values,'revision'=>$row?->revision??0]];
    }
    public function commandBar(): array
    { return [Button::make('Сохранить')->method('save',WikiListScreen::parameters($this->type)),Link::make('К списку')->route('platform.wiki.list',['type'=>$this->type,...WikiListScreen::parameters($this->type)])]; }
    public function layout(): array
    {
        $schema=app(WikiSchema::class);$fields=[Input::make('row.revision')->type('hidden')];
        foreach($schema->fields($this->type) as $field=>$column){$name='row.'.$field;$foreign=$schema->foreigns($this->type)[$field]??null;
            if($foreign){$control=Relation::make($name)->fromClass(Options::class,'label','id')->applyScope('picker',$foreign['type'])->displayAppend('label')->searchColumns('label')->chunk(20)->allowEmpty()->help('Введите часть названия для поиска.');}
            elseif(($options=$schema->choices($this->type,$field))!==null){$control=Select::make($name)->options($options);}
            elseif(in_array($field,['active','archived','published','is_story','kappa_required','optional','found_in_raid','consumed','hard_requirement'],true)){$control=CheckBox::make($name)->sendTrueOrFalse();}
            elseif(preg_match('/INT|REAL|FLOAT|DOUBLE/i',$column->type)){$control=Input::make($name)->type('number')->step(str_contains(strtoupper($column->type),'INT')?'1':'any');}
            else{$control=in_array($field,['name','title','image_url','wiki_url','icon_url','url','patch','valid_from','caption','source_url','author'],true)?Input::make($name):TextArea::make($name)->rows(3);
                $control->set('data-admin-text','1')->set('data-admin-url',AdminInput::urlField($field)?'1':'0')->maxlength($schema->textLimit($field));}
            if(in_array($field,['amount','width','height','grid_width','grid_height','max_uses'],true)){$control->min($this->type==='objectives'&&$field==='amount'?0:1);}
            if(in_array($field,['order_index','level','loyalty_level','level_req','duration_seconds','cost_rub','weight','experience','spawn_chance','x_percent','y_percent'],true)){$control->min(0);}
            if(in_array($field,['x_percent','y_percent'],true)){$control->max(100);}
            if($field==='spawn_chance'){$control->max(1);}
            $control->title($schema->title($field));if($column->notnull&&$column->dflt_value===null){$control->required();}$fields[]=$control;
        }
        $layouts=[Layout::rows($fields)->title('Данные')];
        if($this->recordId!==null){$row=$schema->record($this->type,$this->recordId)->firstOrFail();$links=[];
            foreach($schema->children($this->type) as $child){$count=$schema->query($child['child'])->where($child['field'],$row->{$child['foreign']['column']})->where('archived',0)->count();
                $links[]=Link::make($child['title'].' · '.$count)->route('platform.wiki.list',['type'=>$child['child'],'filter'=>$child['field'],'parent'=>$this->recordId]);}
            if($links!==[]){$layouts[]=Layout::rows($links)->title('Связанные записи — откройте нужный раздел для добавления');}
        }
        return [...$layouts,Layout::view('platform.content-validation')];
    }
    public function save(Request $request,string $type,?int $record=null)
    {
        $input=$request->validate(['row'=>['required','array']])['row'];
        if(($context=WikiListScreen::context($type))&&$record===null){$input[$context['field']]=$request->query('parent');}
        $id=app(WikiEditor::class)->save($type,$record,$input);Toast::info('Сущность и связи сохранены. Данные сайта обновлены.');
        return redirect()->route('platform.wiki.edit',['type'=>$type,'record'=>$id,...WikiListScreen::parameters($type)]);
    }
}
