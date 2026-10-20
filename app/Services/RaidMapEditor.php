<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\ContentDataset;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\{Rule,ValidationException};
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Применяет ограниченный пакет правок карты с CAS, сохраняя ID и все предметные связи. */
final class RaidMapEditor
{
    public const CATEGORIES = ['extract-pmc','extract-scav','extract-shared','transit','spawn-pmc','spawn-scav','spawn-sniper','spawn-other','boss-zone','loot','loose-loot','locked-door','switch','btr','stationary-weapon','hazard','quest'];
    private const LOOT_TYPES = ['duffle-bag','drawer','weapon-box','toolbox','wooden-crate','jacket','pc-block','cash-register','buried-barrel-cache','grenade-box','dead-scav','medbag-smu06','ground-cache','pmc-body','wooden-ammo-box','technical-supply-crate','medcase','plastic-suitcase','safe','ration-supply-crate','medical-supply-crate','civilian-body','bank-safe','bank-cash-register','scav-body','lab-technician-body','shturmans-stash'];

    public function catalog(): array
    {
        return ContentDataset::where('path','raid/maps.json')->firstOrFail()->content();
    }

    private function definition(string $map): array
    {
        foreach($this->catalog() as $definition){if(($definition['id']??null)===$map){return $definition;}}
        abort(404);
    }

    private function dataset(string $map,string $mode): ContentDataset
    {
        return ContentDataset::where('path','raid/'.($mode==='pve'?'pve/':'').$map.'.json')->firstOrFail();
    }

    public function snapshot(string $map,string $mode): array
    {
        $definition=$this->definition($map);$dataset=$this->dataset($map,$mode);
        return ['definition'=>$definition,'revision'=>$dataset->revision(),
            'definitionRevision'=>ContentDataset::where('path','raid/maps.json')->firstOrFail()->revision(),'data'=>$dataset->content()];
    }

    public function save(string $map,string $mode,array $input): array
    {
        $definition=$this->definition($map);
        $floors=array_values(array_unique([$definition['baseFloor']['id'],...array_column($definition['floors'],'id')]));
        if(is_array($input['changes']??null)){
            foreach($input['changes'] as &$change){
                if(!is_array($change)){continue;}
                foreach(['name','description'] as $field){if(array_key_exists($field,$change)){$change[$field]=AdminInput::trim($change[$field]);}}
            }
            unset($change);
        }
        $rules=['revision'=>['required','string','regex:/^[a-f0-9]{64}$/'],'definitionRevision'=>['required','string','regex:/^[a-f0-9]{64}$/'],
            'changes'=>['required','array','min:1','max:100'],
            'changes.*'=>['required','array:id,new,name,description,category,lootType,position,floor,archived,itemId'],
            'changes.*.id'=>['required','string','max:160','distinct'], 'changes.*.new'=>['required','boolean'],
            'changes.*.name'=>['required',...AdminInput::rules('name',255)],
            'changes.*.description'=>['present',...AdminInput::rules('description',10000)],
            'changes.*.category'=>['required','string',Rule::in(self::CATEGORIES)],
            'changes.*.lootType'=>['nullable','string','max:80','regex:/^[a-z0-9-]+$/'],
            'changes.*.itemId'=>['bail','nullable','string','max:128'],
            'changes.*.floor'=>['required','string',Rule::in($floors)],'changes.*.archived'=>['required','boolean'],
            'changes.*.position'=>['required','array:x,y,z']];
        foreach(['x','y','z'] as $axis){$rules['changes.*.position.'.$axis]=['bail','required','numeric',AdminInput::finite(),'between:-10000,10000'];}
        if(array_diff(array_keys($input),['revision','definitionRevision','changes'])){throw ValidationException::withMessages(['changes'=>'Недопустимые поля запроса.']);}
        $valid=Validator::make($input,$rules,AdminInput::messages())->validate();
        return app(WikiSync::class)->run(fn()=>DB::transaction(function()use($map,$mode,$valid): array {
            $catalog=ContentDataset::where('path','raid/maps.json')->lockForUpdate()->firstOrFail();
            $dataset=$this->dataset($map,$mode);$dataset=ContentDataset::whereKey($dataset->id)->lockForUpdate()->firstOrFail();
            if(!hash_equals($catalog->revision(),$valid['definitionRevision'])||!hash_equals($dataset->revision(),$valid['revision'])){
                throw new ConflictHttpException('Карта уже изменилась. Ваши правки сохранены в редакторе. Сравните их с текущей версией.');
            }
            $data=$dataset->content();abort_unless(($data['mapId']??null)===$map,409);
            $indexes=array_flip(array_column($data['pois'],'id'));$changed=[];
            foreach($valid['changes'] as $i=>$change){
                $index=$indexes[$change['id']]??null;
                if($change['new']){
                    if(!preg_match('/^editor-[a-f0-9]{32}$/',$change['id'])||$index!==null){throw ValidationException::withMessages(['changes.'.$i.'.id'=>'Некорректный идентификатор новой точки.']);}
                    if($change['category']==='loot'&&!in_array($change['lootType']??null,self::LOOT_TYPES,true)){throw ValidationException::withMessages(['changes.'.$i.'.lootType'=>'Выберите тип контейнера.']);}
                    if($change['category']!=='loot'&&!empty($change['lootType'])){throw ValidationException::withMessages(['changes.'.$i.'.lootType'=>'Тип контейнера допустим только для слоя контейнеров.']);}
                    $index=count($data['pois']);$old=['id'=>$change['id'],'gameMode'=>$mode,'sourceMapId'=>$map];
                }else{
                    if($index===null){throw ValidationException::withMessages(['changes.'.$i.'.id'=>'Точка не принадлежит выбранной карте и режиму.']);}
                    $old=$data['pois'][$index];
                    if(($old['sourceMapId']??$map)!==$map||($old['gameMode']??$mode)!==$mode){throw ValidationException::withMessages(['changes.'.$i.'.id'=>'Точка принадлежит другой карте или режиму.']);}
                    // Тип существующей точки определяет её связи и иконку, а не форма координат.
                    if($change['category']!==$old['category']||($change['lootType']??null)!==($old['lootType']??null)){
                        throw ValidationException::withMessages(['changes.'.$i.'.category'=>'Категорию существующей точки изменяют в её предметной карточке.']);
                    }
                }
                $point=[...$old,'name'=>$change['name'],'description'=>$change['description'],'category'=>$change['category'],
                    'kind'=>$old['kind']??(str_starts_with($change['category'],'extract-')?'extract':$change['category']),
                    'position'=>array_map('floatval',$change['position']),'floorOverride'=>$change['floor'],'cmsArchived'=>(bool)$change['archived'],
                    'sourceStatus'=>'editor','editorPosition'=>true];
                if($change['new']&&isset($change['lootType'])){$point['lootType']=$change['lootType'];}
                if($change['new']&&$change['category']==='loose-loot'){
                    $item=DB::table('wiki_items')->where('external_id',$change['itemId']??'')->where('archived',0)->first();
                    if(!$item){throw ValidationException::withMessages(['changes.'.$i.'.itemId'=>'Выберите существующий предмет.']);}
                    $point['itemIds']=[$item->external_id];$point['itemNames']=[$item->name];
                }
                if(!$change['new']&&!empty($change['itemId'])){throw ValidationException::withMessages(['changes.'.$i.'.itemId'=>'Связи существующей точки меняют в предметной карточке.']);}
                // Переносим контур вместе с точкой, сохраняя форму зоны.
                if(isset($old['position'],$old['outline'])){foreach($point['outline'] as &$vertex){foreach(['x','y','z'] as $axis){$vertex[$axis]+=$point['position'][$axis]-$old['position'][$axis];}}unset($vertex);}
                foreach(['top','bottom'] as $height){if(isset($old[$height],$old['position'])){$point[$height]+=$point['position']['y']-$old['position']['y'];}}
                unset($point['hiddenByDefault'],$point['reviewReason']);
                if(isset($point['navigator'])){$point['sourceNavigator']=$point['navigator'];unset($point['navigator']);}
                $data['pois'][$index]=app(WikiSync::class)->reconcileDataset($old,$point);$changed[]=$point['id'];
            }
            $previousRef=(string)$dataset->getRawOriginal('payload');$dataset->setContent($data);$dataset->save();
            app(WikiImporter::class)->changedPoints($dataset,$changed,$previousRef);
            return ['revision'=>$dataset->revision(),'saved'=>$changed];
        }));
    }
}
