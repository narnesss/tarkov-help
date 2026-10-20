<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Services\RaidMapEditor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

/** Закрытые JSON-операции редактора под сессией и CSRF Orchid. */
final class RaidMapEditorController
{
    public function data(Request $request,RaidMapEditor $editor)
    {
        abort_unless($request->user()?->hasAccess('wiki.content'),403);
        if(!$request->query->has('map')){return response()->json(['maps'=>$editor->catalog()]);}
        [$map,$mode]=$this->scope($request,$editor);
        return response()->json($editor->snapshot($map,$mode));
    }
    public function save(Request $request,RaidMapEditor $editor)
    {
        abort_unless($request->user()?->hasAccess('wiki.content'),403);
        [$map,$mode]=$this->scope($request,$editor);
        abort_if(strlen($request->getContent())>262144,413);
        return response()->json($editor->save($map,$mode,$request->json()->all()));
    }
    public function items(Request $request)
    {
        abort_unless($request->user()?->hasAccess('wiki.content'),403);
        $search=\App\Services\AdminInput::search($request->query('search'));
        return response()->json(['items'=>DB::table('wiki_items')->where('archived',0)->whereRaw("name LIKE ? ESCAPE '!'",['%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%'])->orderBy('name')->limit(30)->get(['external_id as id','name'])]);
    }
    private function scope(Request $request,RaidMapEditor $editor): array
    {
        $v=validator($request->query(),['map'=>['bail','required','string',Rule::in(array_column($editor->catalog(),'id'))],
            'mode'=>['bail','required','string',Rule::in(['regular','pve'])]])->validate();
        return [$v['map'],$v['mode']];
    }
}
