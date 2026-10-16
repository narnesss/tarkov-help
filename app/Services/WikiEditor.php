<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule,ValidationException};

/** Валидирует типизированные формы, внешние ключи и деревья перед атомарной публикацией. */
final class WikiEditor
{
    public function __construct(private WikiSchema $schema,private WikiStore $store,private WikiSync $sync) {}
    public function save(string $type,?int $id,array $input): int
    {
        return $this->sync->run(fn()=>DB::transaction(function () use($type,$id,$input): int {
            $table=$this->schema->table($type);$old=$id===null?null:$this->schema->record($type,$id)->firstOrFail();
            Validator::make(['row'=>$input],['row.revision'=>['sometimes','integer','min:0']])->validate();
            if($old!==null&&(string)$old->revision!==(string)($input['revision']??'')){$this->error('revision','Запись уже изменилась. Обновите страницу.');}
            $fields=$this->schema->fields($type);$foreigns=$this->schema->foreigns($type);$rules=[];
            if(array_diff_key($input,$fields+['revision'=>true])){$this->error('row','Недопустимые поля формы.');}
            foreach($fields as $name=>$column){
                $required=$column->notnull&&$column->dflt_value===null;
                $base=['bail',$required?'required':'nullable'];
                if(isset($foreigns[$name])){$base[]='integer';}
                elseif(in_array($name,['active','archived','published','is_story','kappa_required','optional','found_in_raid','consumed','hard_requirement'],true)){$base[]='boolean';}
                elseif(($choices=$this->schema->choices($type,$name))!==null){$base[]='string';$base[]=Rule::in(array_keys($choices));}
                elseif(str_contains(strtoupper($column->type),'INT')){$base[]='integer';}
                elseif(preg_match('/REAL|FLOAT|DOUBLE/i',$column->type)){$base[]='numeric';$base[]=AdminInput::finite();}
                else{if(array_key_exists($name,$input)){$input[$name]=AdminInput::trim($input[$name]);}$base=[...$base,...AdminInput::rules($name,$this->schema->textLimit($name))];}
                $rules['row.'.$name]=$base;
            }
            $values=Validator::make(['row'=>$input],$rules,AdminInput::messages())->validate()['row'];
            foreach($values as $field=>&$value){$column=$fields[$field];
                if(isset($foreigns[$field])){
                    if($value===null||$value===''){$value=null;continue;}
                    $foreign=$foreigns[$field];$target=$this->schema->record($foreign['type'],$value)->first();
                    if(!$target||(($target->archived||isset($target->active)&&!$target->active)&&($old?->$field??null)!=$target->{$foreign['column']})){$this->error($field,'Выберите действующую запись по названию.');}
                    $value=$target->{$foreign['column']};
                } elseif($value===null||$value===''){
                    $value=$column->notnull?($this->defaultValue($column)):null;
                } elseif(str_contains(strtoupper($column->type),'INT')){$value=(int)$value;}
                elseif(preg_match('/REAL|FLOAT|DOUBLE/i',$column->type)){$value=(float)$value;}
            } unset($value);
            if($old===null){
                $automatic='custom-'.bin2hex(random_bytes(12));
                foreach(['external_id','slug','code'] as $field){if(isset($this->schema->columns($type)[$field])){$values[$field]=$automatic;}}
                if(isset($this->schema->columns($type)['source_path'])){$values['source_path']='cms:'.$automatic;}
                if($type==='map_points'){$values['source_kind']='editor';}
            }
            $all=[...($old?->getAttributes()??[]),...$values];$this->validateGraph($type,$id,$all);
            // Владельца существующей связи меняют через новую связь и архив прежней.
            $owners=['keys'=>['item_id'],'extractions'=>['point_id'],'objective_groups'=>['objective_id'],
                'prerequisite_groups'=>['quest_id'],'objective_items'=>['group_id'],'quest_prerequisites'=>['group_id'],
                'reward_sets'=>['quest_id','timing'],'media_links'=>['point_id','quest_id','objective_id']];
            $locked=$owners[$type]??(str_starts_with($type,'objective_')?['objective_id']:(str_starts_with($type,'reward_')?['reward_set_id']:(str_starts_with($type,'quest_')?['quest_id']:[])));
            if(in_array($type,['point_positions','point_world_positions','point_documents','item_spawns','key_usages','point_category_links'],true)){$locked=['point_id'];}
            foreach($locked as $field){if($old!==null&&($old->$field??null)!=($all[$field]??null)){$this->error($field,'Для переноса создайте новую связь и отправьте прежнюю в архив.');}}
            if($type==='point_positions'){$values['location_id']=$all['location_id'];}
            if($old!==null&&$type==='quests'&&$old->legacy_quest_id&&($old->mode!==$all['mode']||$old->version_id!=$all['version_id'])){$this->error('mode','Режим и версия существующего квеста связаны с прогрессом и не меняются.');}
            if($old!==null&&$type==='objectives'&&$old->quest_id!=$all['quest_id']){$this->error('quest_id','Цель с существующим прогрессом нельзя перенести в другой квест.');}
            $values['display_name']=$this->schema->label($type,$all);
            $values['search_text']=WikiSchema::search($values['display_name']);
            try{
                if($old===null){$id=(int)DB::table($table)->insertGetId($values);}
                else{$values['revision']=$old->revision+1;if($this->schema->record($type,$id)->where('revision',$old->revision)->update($values)!==1){$this->error('revision','Запись уже изменилась. Обновите страницу.');}}
                app(WikiPublisher::class)->publish($type,$id,$old?->getAttributes()??[]);
            } catch(\Illuminate\Database\QueryException $error){
                if(in_array((string)($error->errorInfo[0]??''),['23000','45000'],true)||str_contains($error->getMessage(),'constraint')){$this->error('row','Проверьте связи и числа: запись нарушает ограничение БД или уже существует.');}
                throw $error;
            }
            return $id;
        }));
    }
    private function defaultValue(object $column): mixed
    {
        $value=$column->dflt_value;
        if($value===null){return '';}
        if(is_numeric($value)){return str_contains(strtoupper($column->type),'INT')?(int)$value:(float)$value;}
        return trim($value,"'\"");
    }
    private function validateGraph(string $type,?int $id,array &$v): void
    {
        foreach(['amount','width','height','grid_width','grid_height','max_uses'] as $field){if(isset($v[$field])&&$v[$field]<=0&& !($type==='objectives'&&$field==='amount')){$this->error($field,'Значение должно быть больше нуля.');}}
        foreach(['order_index','level','loyalty_level','level_req','duration_seconds','cost_rub','weight','experience'] as $field){if(isset($v[$field])&&$v[$field]<0){$this->error($field,'Значение не может быть отрицательным.');}}
        foreach(['x_percent','y_percent'] as $field){if(isset($v[$field])&&($v[$field]<0||$v[$field]>100)){$this->error($field,'Координата должна быть от 0 до 100 процентов.');}}
        if(isset($v['spawn_chance'])&&($v['spawn_chance']<0||$v['spawn_chance']>1)){$this->error('spawn_chance','Вероятность должна быть от 0 до 1.');}
        if($type==='point_positions'){
            $point=DB::table('wiki_map_points')->find($v['point_id']);$map=DB::table('wiki_maps')->find($v['map_id']);
            if(!$point||!$map||$map->location_id!==$point->location_id){$this->error('map_id','Изображение и точка должны принадлежать одной локации.');}
            $v['location_id']=$point->location_id;
            if(!empty($v['layer_id'])&&!DB::table('wiki_map_layers')->where(['id'=>$v['layer_id'],'map_id'=>$v['map_id']])->exists()){$this->error('layer_id','Этаж принадлежит другому изображению карты.');}
        }
        if(in_array($type,['objective_groups','prerequisite_groups'],true)&&!empty($v['parent_id'])){
            $owner=$type==='objective_groups'?'objective_id':'quest_id';$parent=DB::table('wiki_'.$type)->find($v['parent_id']);
            if(!$parent||$parent->$owner!=$v[$owner]){$this->error('parent_id','Родительская группа относится к другой записи.');}
            $seen=[];$cursor=$parent;
            while($cursor){if($cursor->id===$id||isset($seen[$cursor->id])){$this->error('parent_id','Нельзя создавать цикл групп.');}if(count($seen)>=32){$this->error('parent_id','Допустимо не более 32 уровней групп.');}$seen[$cursor->id]=true;$cursor=$cursor->parent_id?DB::table('wiki_'.$type)->find($cursor->parent_id):null;}
        }
        if($type==='quest_prerequisites'){
            $group=DB::table('wiki_prerequisite_groups')->find($v['group_id']);$quest=DB::table('wiki_quests')->find($group->quest_id);
            $parent=DB::table('wiki_quests')->find($v['prerequisite_quest_id']);
            if(!$parent||$parent->mode!==$quest->mode||$parent->version_id!==$quest->version_id){$this->error('prerequisite_quest_id','Квесты должны иметь один режим и версию данных.');}
            $pending=[$parent->id];$seen=[];
            while($pending){$current=array_pop($pending);if($current===$quest->id){$this->error('prerequisite_quest_id','Связь создаёт цикл квестов.');}if(isset($seen[$current])){continue;}$seen[$current]=true;
                $edges=DB::table('wiki_quest_prerequisites as e')->join('wiki_prerequisite_groups as g','g.id','=','e.group_id')->where('g.quest_id',$current)->where('e.archived',0)->where('g.archived',0);
                if($id!==null){$edges->where('e.rowid','<>',$id);}$pending=[...$pending,...$edges->pluck('e.prerequisite_quest_id')->all()];}
        }
        if($type==='objective_points'){
            $objective=DB::table('wiki_objectives')->find($v['objective_id']);$quest=DB::table('wiki_quests')->find($objective->quest_id);$point=DB::table('wiki_map_points')->find($v['point_id']);
            if($point->mode!=='all'&&$point->mode!==$quest->mode){$this->error('point_id','Точка принадлежит другому режиму игры.');}
        }
        if($type==='reward_unlocks'){
            $count=0;foreach(['trader_id','craft_id','offer_id','location_id','achievement_id'] as $field){$count+=(int)!empty($v[$field]);}
            if($count!==1){$this->error('row','Выберите ровно одну открываемую возможность.');}
        }
        if($type==='media_links'){
            $count=0;foreach(['point_id','quest_id','objective_id'] as $field){$count+=(int)!empty($v[$field]);}
            if($count!==1){$this->error('row','Выберите ровно одну запись для привязки медиа.');}
        }
    }
    private function error(string $field,string $message): never
    { throw ValidationException::withMessages(['row.'.$field=>$message]); }
}
