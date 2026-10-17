<?php
declare(strict_types=1);
namespace App\Services;

use EftWiki\Identity\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
        $this->update($session, ['display_name'=>$name]);
    }

    /** Контакты и анкета выдаются отдельно и не попадают в сохраняемый клиентом session.profile. */
    public function details(Session $session): array
    {
        abort_unless($session->username!==null,403,'Сначала войдите в аккаунт.');
        $details=DB::table('player_profiles')->where('user_id',$session->userId)->first();
        $email=DB::table('player_emails')->where('user_id',$session->userId)->value('email');
        $providerEmail=DB::table('player_identities')->where('user_id',$session->userId)->whereNotNull('verified_email')->orderBy('created_at')->orderBy('provider')->value('verified_email');
        return ['email'=>$email??$providerEmail, 'passwordResetAvailable'=>$email!==null, 'version'=>$this->version($details),
            'phone'=>$details?->phone, 'country'=>$details?->country,
            'city'=>$details?->city, 'gender'=>$details?->gender,
            'age'=>$details?->age!==null?(int)$details->age:null,
            'registeredAt'=>(int)DB::table('users')->where('id',$session->userId)->value('created_at'),
            'lastActiveAt'=>DB::table('player_activity')->where('user_id',$session->userId)->value('last_active_at')];
    }

    private function version(?object $details): string
    {
        $values=[];
        foreach(['display_name','phone','country','city','gender','age'] as $field){$values[$field]=$details->{$field}??null;}
        if($values['age']!==null){$values['age']=(int)$values['age'];}
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }

    public function update(Session $session, array $input): void
    {
        abort_unless($session->username!==null,403,'Сначала войдите в аккаунт.');
        foreach(['display_name','phone','country','city','gender','age'] as $field){
            if(array_key_exists($field,$input)){
                $input[$field]=AdminInput::trim($input[$field]);
                if($field!=='display_name'&&$input[$field]===''){$input[$field]=null;}
            }
        }
        if(is_string($input['phone']??null)){$input['phone']=preg_replace('/[ ()-]/','',$input['phone']);}
        $editingDetails=array_intersect(array_keys($input),['phone','country','city','gender','age'])!==[];
        $data=Validator::make($input,[
            'display_name'=>['bail','required','string','max:64'],
            'phone'=>['bail','sometimes','nullable','string','regex:/^\+[1-9][0-9]{6,14}$/D'],
            'country'=>['sometimes','nullable',...AdminInput::rules('country',80)],
            'city'=>['sometimes','nullable',...AdminInput::rules('city',100)],
            'gender'=>['bail','sometimes','nullable','string','in:male,female,other'],
            'age'=>['bail','sometimes','nullable','integer','between:1,120'],
            'profile_version'=>['bail',$editingDetails?'required':'sometimes','string','regex:/^[a-f0-9]{64}$/D'],
            'email'=>['missing'], 'registeredAt'=>['missing'], 'lastActiveAt'=>['missing'],
        ],[...AdminInput::messages(),'phone.regex'=>'Телефон: + и от 7 до 15 цифр, например +79991234567.'])->validate();
        $name=$data['display_name'];
        foreach(['country','city'] as $field){
            if(is_string($data[$field]??null)&&preg_match('/[\p{C}<>]/u',$data[$field])){
                throw ValidationException::withMessages([$field=>'Введите обычный текст без HTML и управляющих знаков.']);
            }
        }
        if(!mb_check_encoding($name,'UTF-8')||$name===''||mb_strlen($name)>64||preg_match('/[\p{C}<>]/u',$name)){
            throw ValidationException::withMessages(['display_name'=>'Имя: от 1 до 64 символов, без управляющих знаков и < >.']);
        }
        DB::transaction(function()use($session,$data):void{
            DB::table('player_auth_locks')->where('id',1)->lockForUpdate()->first();
            abort_unless(DB::table('sessions')->where('token_hash',hash('sha256',$session->token))->where('user_id',$session->userId)->where('expires_at','>',time())->exists(),401,'Сессия завершена. Войдите снова.');
            abort_unless(DB::table('users')->where('id',$session->userId)->whereNotNull('username')->lockForUpdate()->first(),401);
            $version=$data['profile_version']??null;unset($data['profile_version']);
            if($version!==null){
                $current=DB::table('player_profiles')->where('user_id',$session->userId)->lockForUpdate()->first();
                abort_unless(hash_equals($this->version($current),$version),409,'Профиль изменён в другой вкладке. Обнови страницу перед сохранением.');
            }
            DB::table('player_profiles')->upsert([['user_id'=>$session->userId,...$data,'updated_at'=>time()]],['user_id'],[...array_keys($data),'updated_at']);
        });
    }

    public function initializeOAuth(string $owner, ?string $name, ?string $avatar): void
    {
        DB::table('player_profiles')->insertOrIgnore(['user_id'=>$owner,'display_name'=>$name,'provider_name'=>$name,'avatar_url'=>$avatar,'updated_at'=>time()]);
    }
}
