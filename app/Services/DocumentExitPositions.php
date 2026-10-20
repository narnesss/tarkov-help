<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Читает независимые SQL-позиции для публичной карты без зависимостей от редактора. */
final class DocumentExitPositions
{
    public function forMap(string $mapId,string $mode): array
    {
        return DB::table('wiki_document_exit_positions as v')->join('wiki_map_points as p','p.id','=','v.point_id')
            ->join('wiki_maps as m','m.id','=','v.map_id')->join('wiki_locations as l','l.id','=','m.location_id')
            ->join('wiki_map_layers as f','f.id','=','v.layer_id')->where('l.slug',$mapId)->where('m.slug','documents')
            ->where('m.archived',0)->where('f.archived',0)->where('p.archived',0)->where('p.active',1)->whereIn('p.mode',[$mode,'all'])
            ->orderBy('v.id')->get(['p.external_id as id','p.title as name','v.x_percent as x','v.y_percent as y','f.external_id as floor','v.revision'])
            ->map(fn($row)=>['id'=>$row->id,'name'=>$row->name,'x'=>(float)$row->x,'y'=>(float)$row->y,'floor'=>$row->floor,
                'category'=>'extract-pmc','conditions'=>[],'placementRevision'=>(int)$row->revision])->all();
    }
}
