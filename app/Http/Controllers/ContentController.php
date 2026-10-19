<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\{ContentDataset, Quest};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use App\Services\{PublicCatalog, PublicContentCache};

/** Собирает и кэширует публичный контент без открытия сессии игрока. */
final class ContentController
{
    public function __construct(private readonly PublicContentCache $responses) {}
    private const HOME_DATASETS=['raid/maps.json','maps.json','spawns.json','docs.json'];
    public const BUNDLES=[
        'quest-extras'=>[
            'guide/quest-media.json'=>null,'guide/story.json'=>null,'guide/quest-conditions.json'=>['quests'=>[]],
            'guide/objective-patches.json'=>[],'guide/media-localization.json'=>[],'guide/wiki-quests.json'=>['quests'=>[]],
            'guide/gunsmith.json'=>['quests'=>[]],'guide/catalog-update.json'=>null,'guide/quest-map-index.json'=>null,
            'guide/quest-choices.json'=>[],'guide/quest-reward-support.json'=>[],'guide/quest-patch-1150.json'=>[],
            'guide/ticket-map.json'=>null,'guide/quest-content.json'=>null,
        ],
        'raid-base'=>[
            'raid/maps.json'=>null,'raid/item-names.json'=>[],'raid/document-calibration.json'=>null,
            'raid/quest-index.json'=>null,'guide/btr.json'=>null,'raid/loose-items.json'=>null,
        ],
        'quest-ui'=>['guide/quest-choices.json'=>[],'guide/quest-map-index.json'=>null,'guide/quest-reward-support.json'=>[],'guide/ticket-map.json'=>null,'guide/quest-conditions.json'=>['quests'=>[]]],
    ];

    /** Один запрос к PHP вместо последовательной очереди мелких файлов; список задаёт сервер. */
    public function bundle(string $name,Request $request)
    {
        $request->validate(['revision'=>['sometimes','boolean']]);
        abort_unless(isset(self::BUNDLES[$name]),404);$paths=self::BUNDLES[$name];
        $datasets=ContentDataset::whereIn('path',array_keys($paths))->orderBy('path')->get()->keyBy('path');
        foreach($paths as $path=>$fallback){abort_if($fallback===null&&!$datasets->has($path),404);}
        $database=\Illuminate\Support\Facades\DB::connection();
        $key='public-content-bundle-v1-'.hash('sha256',serialize([$database->getName(),$database->getDatabaseName(),config('content.directory'),$name]));
        $revision=hash('sha256',serialize($datasets->map(fn(ContentDataset $dataset)=>$dataset->getRawOriginal('payload'))->all()));
        return $this->responses->respond($request,$key,$revision,function() use($paths,$datasets): string {
            // Не удерживаем распакованные PHP-массивы всех справочников одновременно.
            $files=[];foreach($paths as $path=>$fallback){
                $raw=$datasets->has($path)?$this->build($datasets[$path],$path,new Collection())->getContent():json_encode($fallback,JSON_THROW_ON_ERROR);
                $files[]=json_encode($path,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).':'.$raw;
            }
            return '{"files":{'.implode(',',$files).'}}';
        });
    }

    public function __invoke(string $path,Request $request)
    {
        abort_unless(preg_match('~^[a-z0-9][a-z0-9/._-]*\.json$~', $path) && !str_contains($path, '..'), 404);
        $input=$request->validate(['view'=>['sometimes','string','in:full,catalog,detail,lookup,documents'],'map'=>['sometimes','string','max:128','regex:/^[a-zA-Z0-9_-]+$/D'],'mode'=>['sometimes','string','in:pve,regular'],'section'=>['bail','sometimes','string',\Illuminate\Validation\Rule::in(array_keys(PublicCatalog::SECTIONS))],'id'=>['sometimes','prohibited_unless:view,detail','string','max:128','regex:/^[a-zA-Z0-9_-]+$/D']]);
        $request->validate(['revision'=>['sometimes','boolean']]);
        $view=$input['view']??'full';$id=$input['id']??null;
        if($view==='documents'){abort_unless($path==='maps.json'&&isset($input['map']),422);return $this->documents($request,$input['map'],$input['mode']??'pve');}
        if($path==='guide/quest-map-index.json'&&$view==='detail'){return $this->sourceMap($request,$id);}
        if($path==='guide/bosses.json'&&$view==='detail'){return $this->boss($request,$id);}
        if(isset($input['section'])){abort_unless(preg_match('~^guide/reference-(pve|regular)\.json$~',$path)&&$view==='catalog',422);}
        if($view!=='full'){
            abort_unless(preg_match('~^guide/(quests|items|reference)-(pve|regular)\.json$~',$path,$kind),422);
            abort_if($view==='detail'&&($kind[1]==='reference'||$id===null),422);
            abort_if($view==='lookup'&&!in_array($kind[1],['quests','items'],true),422);
        }
        if(str_starts_with($path,'wiki/')){return response()->json(app(\App\Services\WikiGraph::class)->read($path))->header('Cache-Control','private, no-store');}
        $dataset = ContentDataset::where('path', $path)->firstOrFail();
        $questMode=preg_match('~^guide/quests-(pve|regular)\.json$~',$path,$match)?$match[1]:null;
        $quests=new Collection();
        if($path==='home.json'||($questMode!==null&&$view!=='lookup')){
            // Берём SQL-снимок ссылок на неизменяемые файлы; на HIT файлы квестов не открываем.
            $quests=Quest::where('published',true)->when($questMode!==null,fn($q)=>$q->where('mode',$questMode))
                ->when($view==='detail',fn($q)=>$q->where('quest_id',$id))
                ->orderBy('id')->get(['id','quest_id','mode','name','trader_id','payload']);
        }
        abort_if($view==='detail'&&$questMode!==null&&$quests->isEmpty(),404);
        $versions=$quests->map(fn(Quest $quest)=>$quest->getAttributes())->all();
        $related=$path==='home.json'?ContentDataset::whereIn('path',self::HOME_DATASETS)->orderBy('path')->get()->keyBy('path'):new Collection();
        $key='public-content-json-v1-'.hash('sha256',serialize([
            $dataset->getConnection()->getName(),$dataset->getConnection()->getDatabaseName(),config('content.directory'),
            $path,...($view==='full'?[]:[$view,$id]),$input['section']??null,
        ]));
        $dependencies=[];$mediaRevision=null;
        if($view==='detail'){
            $mediaRevision=\App\Services\PageContent::mediaRevision();
            $dependencies=ContentDataset::orderBy('path')->get(['path','payload'])->map(fn($row)=>$row->getAttributes())->all();
            $versions=Quest::where('mode',$questMode??(str_contains($path,'-pve.')?'pve':'regular'))->orderBy('id')->get(['id','quest_id','mode','name','published','payload'])->map(fn($row)=>$row->getAttributes())->all();
        }
        $revision=hash('sha256',serialize([$dataset->getRawOriginal('payload'),$versions,$dependencies,$mediaRevision,$related->map(fn(ContentDataset $row)=>$row->getRawOriginal('payload'))->all()]));
        return $this->responses->respond($request,$key,$revision,fn()=>$this->build($dataset,$path,$quests,$view,$id,$input['section']??null,$related)->getContent());
    }

    private function documents(Request $request,string $mapId,string $mode)
    {
        $raidId=\App\Services\DocumentMapOverlay::ALIASES[$mapId]??$mapId;
        $paths=['maps.json','spawns.json','raid/maps.json','raid/'.($mode==='pve'?'pve/':'').$raidId.'.json'];
        $datasets=ContentDataset::whereIn('path',$paths)->orderBy('path')->get()->keyBy('path');
        abort_unless($datasets->has('maps.json')&&$datasets->has('spawns.json'),404);
        $keys=\Illuminate\Support\Facades\DB::table('wiki_map_points as p')->join('wiki_key_usages as k','k.point_id','=','p.id')
            ->join('wiki_items as i','i.id','=','k.key_item_id')->where('p.archived',0)->where('p.active',1)->where('k.archived',0)->where('i.archived',0)
            ->whereExists(fn($query)=>$query->selectRaw('1')->from('wiki_sources as s')->whereColumn('s.point_id','p.id')->where('s.format','document-point'))
            ->orderBy('p.id')->orderBy('k.key_item_id')->orderBy('k.usage_type')->get(['p.external_id','k.usage_type','i.external_id as item','i.name']);
        $catalog=\Illuminate\Support\Facades\DB::table('wiki_keys as k')->join('wiki_items as i','i.id','=','k.item_id')->where('k.archived',0)->where('i.archived',0)->orderBy('i.id')->get(['i.external_id as id','i.name','i.icon_url as iconLink'])->map(fn($row)=>(array)$row)->all();
        $revision=hash('sha256',serialize([$datasets->map(fn($d)=>$d->getRawOriginal('payload'))->all(),$keys->all(),$catalog,config('document-overlays')]));
        $key='document-overlays-v2-'.hash('sha256',serialize([config('content.directory'),$datasets['maps.json']->getConnection()->getDatabaseName(),$mapId,$mode]));
        return $this->responses->respond($request,$key,$revision,function() use($datasets,$paths,$mapId,$raidId,$mode,$keys,$catalog): string {
            $map=collect($datasets['maps.json']->content())->first(fn($row)=>($row['id']??null)===$mapId&&empty($row['cmsArchived']));abort_unless($map,404);
            $definition=collect($datasets->get('raid/maps.json')?->content()??[])->firstWhere('id',$raidId)??[];
            $relations=[];foreach($keys as $row)$relations[$row->external_id][]=array_intersect_key((array)$row,array_flip(['item','name','usage_type']));
            $data=app(\App\Services\DocumentMapOverlay::class)->build($map,$datasets['spawns.json']->content(),$definition,$datasets->get($paths[3])?->content()??[],$relations,$catalog);
            return json_encode([...$data,'mode'=>$mode,'available'=>$datasets->has($paths[3])&&$definition!==[]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        });
    }

    private function sourceMap(Request $request,?string $id)
    {
        $map=$request->input('map');$mode=$request->input('mode','pve');abort_unless($id!==null&&is_string($map),422);
        $quest=Quest::where('quest_id',$id)->where('mode',$mode)->where('published',true)->firstOrFail();
        $dataset=ContentDataset::where('path','guide/quest-map-index.json')->firstOrFail();
        $key='public-source-map-'.hash('sha256',serialize([config('content.directory'),$dataset->getConnection()->getDatabaseName(),$id,$map,$mode]));
        $revision=hash('sha256',serialize([$dataset->getRawOriginal('payload'),$quest->revision()]));
        return $this->responses->respond($request,$key,$revision,function() use($dataset,$id,$map): string {
            $data=$dataset->content();abort_unless(isset($data['sourceMaps'][$map],$data['sourceQuests'][$id][$map]),404);
            return json_encode(['sourceMaps'=>[$map=>$data['sourceMaps'][$map]],'sourceQuests'=>[$id=>[$map=>$data['sourceQuests'][$id][$map]]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        });
    }

    private function boss(Request $request,?string $id)
    {
        abort_unless($id!==null,422);$mode=$request->input('mode','pve');
        $catalog=ContentDataset::where('path','guide/bosses.json')->firstOrFail();
        $reference=ContentDataset::where('path','guide/reference-'.$mode.'.json')->firstOrFail();
        $key='public-boss-detail-'.hash('sha256',serialize([config('content.directory'),config('database.default'),$catalog->getConnection()->getDatabaseName(),$id,$mode]));
        $revision=hash('sha256',serialize([$catalog->getRawOriginal('payload'),$reference->getRawOriginal('payload')]));
        return $this->responses->respond($request,$key,$revision,fn()=>json_encode(app(\App\Services\PageContent::class)->boss($catalog->content(),\App\Services\PublicJson::select($reference->json(),['meta'],['maps'=>fn($row)=>empty($row['cmsArchived'])?$row:null]),$id),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    private function build(ContentDataset $dataset,string $path,Collection $quests,string $view='full',?string $id=null,?string $section=null,?Collection $related=null)
    {
        if ($path === 'home.json') { return $this->home($dataset,$quests,$related??new Collection()); }
        if (!preg_match('~^guide/quests-(pve|regular)\.json$~', $path, $match)) {
            $raw=$dataset->json();
            if(preg_match('~^guide/items-(pve|regular)\.json$~',$path,$itemMode)&&in_array($view,['detail','lookup'],true)){
                $data=\App\Services\PublicJson::select($raw,['meta'],['items'=>static function($row) use($view,$id){
                    if(!empty($row['cmsArchived'])||($view==='detail'&&($row['id']??null)!==$id))return null;
                    return $view==='lookup'?array_intersect_key($row,array_flip(['id','name'])):$row;
                }]);unset($raw);$data['catalog']=false;
                if($view==='lookup')$data['lookup']=true;
                else{abort_if(empty($data['items']),404);$data=app(\App\Services\PageContent::class)->item($data,$id,$itemMode[1]);}
                return response()->json($data)->header('Cache-Control','private, no-store');
            }
            if($section!==null){
                $collections=array_fill_keys(PublicCatalog::SECTIONS[$section],static fn($row)=>empty($row['cmsArchived'])?$row:null);
                $data=\App\Services\PublicJson::select($raw,['meta'],$collections);unset($raw);$data=PublicCatalog::dataset($data,$path,$view,$id,$section);
                return response()->json($data)->header('Cache-Control','private, no-store');
            }
            $data=json_decode($raw,true,128,JSON_THROW_ON_ERROR);$changed=false;
            foreach(['pois','items','maps','traders'] as $field){if(is_array($data[$field]??null)){$filtered=array_filter($data[$field],fn($row)=>!is_array($row)||(empty($row['cmsArchived'])&&($field!=='pois'||($row['sourceStatus']??null)!=='unverified-coordinates')));if(count($filtered)!==count($data[$field])){$data[$field]=array_values($filtered);$changed=true;}}}
            if(array_is_list($data)){$filtered=array_filter($data,fn($row)=>!is_array($row)||empty($row['cmsArchived']));if(count($filtered)!==count($data)){$data=array_values($filtered);$changed=true;}}
            if($view!=='full'){$data=PublicCatalog::dataset($data,$path,$view,$id,$section);$changed=true;}
            if($changed){return response()->json($data)->header('Cache-Control','private, no-store');}
            return response($raw, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'private, no-store']);
        }
        $data = $dataset->content();
        $tasks = []; $seen = []; $traders = collect($data['traders'] ?? [])->keyBy('id');
        // SQL-снимок содержит только метаданные и ссылки; полные цели распаковываем по одному квесту.
        // Ответ собирается до освобождения GuardDatabaseRestore, без отложенных DB-запросов.
        foreach ($quests as $quest) {
            $raw = $quest->json();$task=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
            $goals=array_values(array_filter($task['objectives']??[],fn($goal)=>empty($goal['cmsArchived'])));
            if(count($goals)!==count($task['objectives']??[])){$task['objectives']=$goals;$raw=json_encode($task,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
            if($view==='catalog'){$raw=json_encode(PublicCatalog::quest($task),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
            if($view==='detail'){
                $data=app(\App\Services\PageContent::class)->quest($data,$task,$match[1]);
                $task=$data['selectedTask'];unset($data['selectedTask']);$raw=json_encode($task,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            }
            $tasks[] = $raw;
            $trader = $task['trader'] ?? null;
            if (is_array($trader) && isset($trader['id']) && !isset($seen[$trader['id']])) {
                $traders->put($trader['id'], [...($traders->get($trader['id']) ?? []), ...$trader]);
                $seen[$trader['id']] = true;
            }
        }
        $data['traders'] = $traders->values()->all(); $data['cms'] = true; unset($data['tasks']);
        if($view!=='full'){$data=PublicCatalog::questGlobals($data,$view);}
        $json = substr(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 0, -1).',"tasks":['.implode(',', $tasks).']}';
        return response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }

    /** Главная страница и поиск используют актуальные названия и только опубликованные квесты. */
    private function home(ContentDataset $dataset,Collection $quests,Collection $related)
    {
        $data = $dataset->content(); $traders = [];
        // Считаем доступные сущности из действующих файлов CMS, а не из снимка главной.
        $active=static fn(array $rows): array=>array_values(array_filter($rows,static fn($row)=>is_array($row)&&empty($row['cmsArchived'])));
        $maps=$active($related->get('raid/maps.json')?->content()??[]);
        $documentMaps=$active($related->get('maps.json')?->content()??[]);
        $mapIds=array_column($documentMaps,'id');
        $points=array_values(array_filter($active($related->get('spawns.json')?->content()??[]),static fn($row)=>in_array($row['map']??null,$mapIds,true)));
        $data['maps']=array_map(static fn($map)=>['id'=>$map['id'],'name'=>$map['name']??$map['displayName']??$map['id']],$maps);
        $data['statistics']=['maps'=>$related->has('raid/maps.json')?count($maps):null,
            'documentMaps'=>$related->has('maps.json')?count($documentMaps):null,
            'documentPoints'=>$related->has('maps.json')&&$related->has('spawns.json')?count($points):null,
            'documentTypes'=>$related->has('docs.json')?count($active($related->get('docs.json')->content())):null];
        foreach (['pve', 'regular'] as $mode) {
            $traders[$mode] = collect($data['modes'][$mode]['traders'] ?? [])->keyBy('id')->map(fn ($trader) => [...$trader, 'total' => 0])->all();
            $data['modes'][$mode] = ['total' => 0, 'kappa' => 0, 'story' => 0, 'tasks' => [], 'traders' => []];
        }
        foreach ($quests as $quest) {
            $task = $quest->payload; $mode = $quest->mode; $story = (bool) ($task['story'] ?? false);
            $archive = ($task['catalogSection'] ?? '') === 'supplementary';
            $trader = is_array($task['trader'] ?? null) ? $task['trader'] : ['id' => $quest->trader_id, 'name' => $quest->trader_id];
            $data['modes'][$mode]['tasks'][] = ['id' => $quest->quest_id, 'name' => $quest->name,
                'story' => $story, 'archive' => $archive, 'trader' => $trader['id']];
            if ($archive) { continue; }
            $data['modes'][$mode]['total']++;
            $data['modes'][$mode]['kappa'] += (int) ($task['kappaRequired'] ?? false);
            $data['modes'][$mode]['story'] += (int) $story;
            if (!$story && $trader['id']) {
                $previous = $traders[$mode][$trader['id']] ?? ['total' => 0];
                $traders[$mode][$trader['id']] = [...$previous, 'id' => $trader['id'], 'name' => $trader['name'] ?? $trader['id'],
                    'image' => $previous['image'] ?? $trader['imageLink'] ?? null, 'total' => $previous['total'] + 1];
            }
        }
        foreach ($traders as $mode => $records) { $data['modes'][$mode]['traders'] = array_values(array_filter($records, fn ($trader) => $trader['total'] > 0)); }
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
