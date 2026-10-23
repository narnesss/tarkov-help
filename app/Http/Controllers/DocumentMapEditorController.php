<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Services\DocumentMapEditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, Http};
use Illuminate\Validation\Rule;

/** Закрытый API Orchid: права, CSRF web-сессии и белый список подложек. */
final class DocumentMapEditorController
{
    private function selection(Request $request): array
    {
        abort_unless($request->user()?->hasAccess('wiki.content'),403);
        return $request->validate(['map'=>['bail','required','string','regex:/^[a-z][a-z-]{0,40}$/'],
            'mode'=>['required','string',Rule::in(['pve','regular'])]]);
    }

    public function data(Request $request,DocumentMapEditor $editor)
    {
        $selection=$this->selection($request);
        $values=$request->validate(['archived'=>['sometimes','boolean']]);
        return response()->json($editor->snapshot($selection['map'],$selection['mode'],(bool)($values['archived']??false)));
    }

    public function audit(Request $request)
    {
        abort_unless($request->user()?->hasAccess('wiki.audit.read'),403);
        $values=$request->validate(['cursor'=>['bail','nullable','integer','min:1']]);
        $rows=\Illuminate\Support\Facades\DB::table('wiki_document_map_audit')->when($values['cursor']??null,fn($q,$cursor)=>$q->where('id','<',$cursor))
            ->orderByDesc('id')->limit(51)->get();
        return response()->json(['events'=>$rows->take(50)->values(),'nextCursor'=>$rows->count()>50?$rows[49]->id:null]);
    }

    public function save(Request $request,DocumentMapEditor $editor)
    {
        try{
            $selection=$this->selection($request);
            $input=$request->except(['map','mode','_token']);
            return response()->json($editor->save($selection['map'],$selection['mode'],$input,(string)$request->user()->getAuthIdentifier()));
        }catch(\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $failure){
            if($request->user())app(\App\Services\DocumentMapAudit::class)->append((string)$request->user()->getAuthIdentifier(),null,'denied');
            throw $failure;
        }
    }

    public function remove(Request $request,DocumentMapEditor $editor)
    {
        try{
            $selection=$this->selection($request);
            return response()->json($editor->remove($selection['map'],$selection['mode'],
                $request->except(['map','mode','_token']),(string)$request->user()->getAuthIdentifier()));
        }catch(\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $failure){
            if($request->user())app(\App\Services\DocumentMapAudit::class)->append((string)$request->user()->getAuthIdentifier(),null,'denied',kind:'archive_request');
            throw $failure;
        }
    }

    public function batch(Request $request,DocumentMapEditor $editor)
    {
        try{
            $selection=$this->selection($request);
            return response()->json($editor->saveBatch($selection['map'],$selection['mode'],
                $request->except(['map','mode','_token']),(string)$request->user()->getAuthIdentifier()));
        }catch(\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $failure){
            if($request->user())app(\App\Services\DocumentMapAudit::class)->append((string)$request->user()->getAuthIdentifier(),null,'denied',kind:'document_batch_request');
            throw $failure;
        }
    }

    public function artwork(Request $request,DocumentMapEditor $editor)
    {
        $selection=$this->selection($request);
        $map=collect($editor->maps())->firstWhere('id',$selection['map']);abort_unless($map,404);
        $path=$map['layeredFile']??$map['file'];
        abort_unless(preg_match('~^assets/documents/kord(?:-levels)?-\d{8}/[a-z-]+\.svg$~D',$path),422);
        $limit=8*1024*1024;
        $svg=Cache::remember('document-editor-artwork-'.hash('sha256',config('app.url').$path),3600,function() use($path,$limit){
            if(is_file(public_path($path))){abort_if(filesize(public_path($path))>$limit,503);return file_get_contents(public_path($path));}
            try{
                $deadline=microtime(true)+15;
                $response=Http::connectTimeout(3)->timeout(15)->withOptions(['allow_redirects'=>false,'stream'=>true,'read_timeout'=>3])
                    ->get(rtrim(config('app.url'),'/').'/'.$path);
                abort_unless($response->successful(),503);
                $body=$response->toPsrResponse()->getBody();$svg='';
                try{while(!$body->eof()&&strlen($svg)<=$limit&&microtime(true)<$deadline)$svg.=$body->read(65536);}
                finally{$body->close();}
                abort_if(microtime(true)>=$deadline,503);
            }catch(\Illuminate\Http\Client\ConnectionException|\RuntimeException $failure){abort(503,'Схема временно недоступна. Повторите загрузку.');}
            abort_if(strlen($svg)>$limit||!str_contains(substr($svg,0,500),'<svg'),503);
            return $svg;
        });
        return response($svg,200,['Content-Type'=>'image/svg+xml','X-Content-Type-Options'=>'nosniff',
            'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox"]);
    }
}
