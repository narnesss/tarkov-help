<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use EftWiki\Support\Clock;
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Support\Facades\DB;

/** Формирует шапку по действующей сессии без создания гостя и изменения срока сессии. */
final class PlayerNavigationController
{
    public function __invoke(Request $request,Clock $clock): JsonResponse
    {
        $data=$request->validate(['page'=>['sometimes','string','in:index,account']]);
        $token=$request->cookie('eft_session');$authenticated=false;
        if(is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token)){
            $authenticated=DB::table('sessions as s')->join('users as u','u.id','=','s.user_id')
                ->where('s.token_hash',hash('sha256',$token))->where('s.expires_at','>',$clock->now())->whereNotNull('u.username')->exists();
        }
        return response()->json(['html'=>view('components.player-navigation',[
            'authenticated'=>$authenticated,'page'=>$data['page']??'index',
        ])->render()])->header('Cache-Control','private, no-store')->header('Vary','Cookie');
    }
}
