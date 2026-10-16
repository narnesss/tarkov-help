<?php
declare(strict_types=1);
namespace App\Services;

use EftWiki\Identity\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Публичное имя и аватар не меняют логин, владельца прогресса и способы входа. */
final class PlayerProfiles
{
    public static function providerName(string $name): ?string
    {
        $name=trim(preg_replace('/[\p{C}<>]/u','',$name)??'');
        $name=preg_replace('/\s+/u',' ',$name)??'';
        return $name===''?null:mb_substr($name,0,64);
    }

    public static function avatar(string $provider, array $user): ?string
    {
        if($provider==='discord'){
            $id=$user['id']??null; $hash=$user['avatar']??null;
            return is_string($id)&&preg_match('/^\d{1,20}$/D',$id)&&is_string($hash)&&preg_match('/^(?:a_)?[a-f0-9]{32}$/D',$hash)
                ?'https://cdn.discordapp.com/avatars/'.$id.'/'.$hash.'.png?size=128':null;
        }
        $url=$user['picture']??null;
        if(!is_string($url)||strlen($url)>2048){return null;}
        $parts=parse_url($url); $host=strtolower($parts['host']??'');
        return ($parts['scheme']??'')==='https' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && ($host==='googleusercontent.com'||str_ends_with($host,'.googleusercontent.com')) ? $url : null;
    }

    public function enrich(array $profile): array
    {
        if(!($profile['authenticated']??false)){return $profile;}
        $details=DB::table('player_profiles')->where('user_id',$profile['id'])->first();
        return [...$profile, 'name'=>$details?->display_name??$details?->provider_name??$profile['name'],
            'avatar'=>$details?->avatar_url, 'customName'=>$details?->display_name!==null];
    }

    public function publicData(Session $session): array
    {
        $data=$session->publicData((string)config('app.key'));
        $data['profile']=$this->enrich($data['profile']);
        return $data;
    }

    public function rename(Session $session, string $name): void
    {
        abort_unless($session->username!==null,403,'Сначала войдите в аккаунт.');
        $name=trim($name);
        if($name===''||mb_strlen($name)>64||preg_match('/[\p{C}<>]/u',$name)){
            throw ValidationException::withMessages(['display_name'=>'Имя: от 1 до 64 символов, без управляющих знаков и < >.']);
        }
        DB::transaction(function()use($session,$name):void{
            DB::table('player_auth_locks')->where('id',1)->lockForUpdate()->first();
            abort_unless(DB::table('sessions')->where('token_hash',hash('sha256',$session->token))->where('user_id',$session->userId)->where('expires_at','>',time())->exists(),401,'Сессия завершена. Войдите снова.');
            abort_unless(DB::table('users')->where('id',$session->userId)->whereNotNull('username')->lockForUpdate()->first(),401);
            DB::table('player_profiles')->upsert([['user_id'=>$session->userId,'display_name'=>$name,'updated_at'=>time()]],['user_id'],['display_name','updated_at']);
        });
    }

    public function initializeOAuth(string $owner, ?string $name, ?string $avatar): void
    {
        DB::table('player_profiles')->insertOrIgnore(['user_id'=>$owner,'display_name'=>$name,'provider_name'=>$name,'avatar_url'=>$avatar,'updated_at'=>time()]);
    }
}
