<?php
declare(strict_types=1);
namespace App\Services;

/** Предотвращает повторный импорт при публикации SQLite-связей в совместимые файлы. */
final class WikiSync
{
    public bool $busy = false;
    /** Согласует прежние поля точки с подробными файловыми атрибутами связей. */
    public function reconcileDataset(array $before,array $after): array
    {
        foreach(['cmsItemSpawns'=>['itemIds',['amount'=>1,'spawn_chance'=>null,'notes'=>'']],
            'cmsKeyUsages'=>['keyIds',['usage_type'=>'unlock','notes'=>'']]] as $field=>[$legacy,$defaults]){
            if(isset($before[$field])&&($before[$legacy]??[])!==($after[$legacy]??[])){
                $after[$field]=[];foreach($after[$legacy]??[] as $reference){$item=WikiImporter::external($reference);$found=false;
                    foreach($before[$field] as $link){if($link['item']===$item){$after[$field][]=$link;$found=true;}}
                    if(!$found){$after[$field][]=['item'=>$item,...$defaults];}}
            }
        }
        if(isset($before['cmsPositions'])){
            usort($after['cmsPositions'],fn($a,$b)=>$a['order_index']<=>$b['order_index']);
            foreach([''=>'0','2'=>'1'] as $suffix=>$index){foreach(['x'=>'x_percent','y'=>'y_percent'] as $legacy=>$column){$field=$legacy.$suffix;
                if(isset($after['cmsPositions'][$index])&&($before[$field]??null)!==($after[$field]??null)){$after['cmsPositions'][$index][$column]=$after[$field];}}}
        }
        if(isset($before['cmsWorldPositions'])&&($before['position']??null)!==($after['position']??null)){
            foreach(['x','y','z'] as $axis){if(isset($after['position'][$axis],$after['cmsWorldPositions'][0])){$after['cmsWorldPositions'][0]['world_'.$axis]=$after['position'][$axis];}}
        }
        if(isset($before['cmsDocuments'])&&($before['doc']??null)!==($after['doc']??null)){
            $after['cmsDocuments']=array_values(array_filter($before['cmsDocuments'],fn($id)=>$id!==($before['doc']??null)));
            if(!empty($after['doc'])){array_unshift($after['cmsDocuments'],$after['doc']);}
        }
        foreach($after as $key=>$value){if(is_array($value)&&is_array($before[$key]??null)&&!str_starts_with((string)$key,'cms')){$after[$key]=$this->reconcileDataset($before[$key],$value);}}
        return $after;
    }
    public function quest(\App\Models\Quest $quest): void
    {
        if(!$this->busy&&\Illuminate\Support\Facades\DB::table('wiki_projection_states')->where('owner_type','full')->exists()){
            $this->run(fn()=>\Illuminate\Support\Facades\DB::transaction(fn()=>app(WikiImporter::class)->quest($quest)));
        }
    }
    public function dataset(\App\Models\ContentDataset $dataset): void
    {
        if(!$this->busy&&\Illuminate\Support\Facades\DB::table('wiki_projection_states')->where('owner_type','full')->exists()){
            $this->run(fn()=>\Illuminate\Support\Facades\DB::transaction(fn()=>app(WikiImporter::class)->dataset($dataset)));
        }
    }
    public function run(callable $callback): mixed
    {
        $previous=$this->busy;$this->busy=true;
        try {
            if(!$previous&&in_array(\Illuminate\Support\Facades\DB::getDriverName(),['mysql','mariadb'],true)){
                return \Illuminate\Support\Facades\DB::transaction(function() use($callback){
                    \Illuminate\Support\Facades\DB::table('wiki_write_locks')->where('id',1)->lockForUpdate()->firstOrFail();
                    return $callback();
                });
            }
            return $callback();
        } finally { $this->busy=$previous; }
    }
}
