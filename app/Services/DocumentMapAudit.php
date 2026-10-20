<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Закрытая цепочка событий редактора; имена, координаты и содержимое форм не журналируются. */
final class DocumentMapAudit
{
    public function append(string $actor,?int $point,string $result,?string $before=null,?string $after=null,string $kind='position_request'): void
    {
        app(WikiSync::class)->run(fn()=>DB::transaction(function() use($actor,$point,$result,$before,$after,$kind){
            $row=['event_id'=>(string)Str::uuid(),'actor_ref'=>$actor,'point_id'=>$point,'entity_type'=>$kind,'result'=>$result,
                'revision_before'=>$before,'revision_after'=>$after,'changed_fields'=>$result==='success'?(str_starts_with($kind,'create_')?'point,title,document_id,floor,x_percent,y_percent':'floor,x_percent,y_percent'):'',
                'recorded_at'=>time(),'previous_hash'=>DB::table('wiki_document_map_audit')->orderByDesc('id')->value('signature')??str_repeat('0',64)];
            $row['signature']=hash_hmac('sha256',implode("\n",array_map(fn($value)=>(string)$value,array_values($row))),config('app.key'));
            DB::table('wiki_document_map_audit')->insert($row);
        }));
    }
}
