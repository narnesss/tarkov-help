<?php
declare(strict_types=1);
namespace App\Services;
use EftWiki\Identity\PasswordPolicy;
use Illuminate\Support\Facades\DB;

/** Замена пароля вызывается под блокировкой владельца внутри транзакции авторизации. */
final class PlayerCredentials
{
    public function replace(object $owner,string $password):void
    {
        if(DB::transactionLevel()===0){throw new \LogicException('Смена пароля требует транзакции.');}
        if(($error=PasswordPolicy::error($password,$owner->username))!==null){abort(422,$error);}
        $history=DB::table('player_password_history')->where('user_id',$owner->id)->pluck('password_hash')->all();
        foreach([$owner->password_hash,...$history] as $hash){abort_if(is_string($hash)&&password_verify($password,$hash),422,'Этот пароль уже использовался в аккаунте. Придумай новый.');}
        if(is_string($owner->password_hash)){DB::table('player_password_history')->insert(['user_id'=>$owner->id,'password_hash'=>$owner->password_hash,'created_at'=>time()]);}
        DB::table('users')->where('id',$owner->id)->update(['password_hash'=>password_hash($password,PASSWORD_DEFAULT)]);
        $this->revoke($owner->id);
    }
    public function revoke(string $id):void
    {
        foreach(['sessions','player_email_challenges','player_password_resets'] as $table){DB::table($table)->where('user_id',$id)->delete();}
        DB::table('player_oauth_states')->where('link_user_id',$id)->delete();
    }
}
