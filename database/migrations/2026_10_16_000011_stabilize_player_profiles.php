<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Закрепляет существующее отображение профилей и доступ владельца к управлению игроками. */
return new class extends Migration {
    public function up():void
    {
        DB::table('users')->whereNotNull('username')->orderBy('id')->chunkById(100,function($users):void{
            foreach($users as $user){
                $profile=DB::table('player_profiles')->where('user_id',$user->id)->first();
                if(!$profile){DB::table('player_profiles')->insert(['user_id'=>$user->id,'display_name'=>$user->username,'updated_at'=>time()]);}
                elseif($profile->display_name===null){DB::table('player_profiles')->where('user_id',$user->id)->whereNull('display_name')->update(['display_name'=>$profile->provider_name??$user->username]);}
            }
        });
        // Первоначальный владелец панели получает раздел; остальные редакторы требуют отдельного права.
        $admin=DB::table('admin_users')->orderBy('id')->first();
        if($admin){$permissions=json_decode($admin->permissions??'{}',true,flags:JSON_THROW_ON_ERROR);$permissions['wiki.users']=true;DB::table('admin_users')->where('id',$admin->id)->update(['permissions'=>json_encode($permissions,JSON_THROW_ON_ERROR)]);}
    }
    public function down():void {throw new RuntimeException('Откат закреплённых имён не поддерживается.');}
};
