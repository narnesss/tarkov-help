<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\Player;
use EftWiki\Identity\PasswordPolicy;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\{Rule,ValidationException};

/** Управляет только аккаунтами игроков, сохраняя ID и прогресс при редактировании. */
final readonly class PlayerAdmin
{
    public function __construct(private PlayerCredentials $credentials){}
    public function revision(string $id):string
    {
        $data=['user'=>DB::table('users')->where('id',$id)->first(),'profile'=>DB::table('player_profiles')->where('user_id',$id)->first(),'email'=>DB::table('player_emails')->where('user_id',$id)->first()];
        return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
    }
    private function lock(?string $id,array $input):?object
    {
        DB::table('player_auth_locks')->where('id',1)->lockForUpdate()->first();
        if($id===null)return null;
        $owner=DB::table('users')->where('id',$id)->whereNotNull('username')->lockForUpdate()->first();abort_unless($owner,404);
        abort_unless(is_string($input['revision']??null)&&hash_equals($this->revision($id),$input['revision']),409,'Пользователь изменён. Обновите страницу перед сохранением.');
        return $owner;
    }
    public function save(?string $id,array $input):Player
    {
        $input['username']=is_string($input['username']??null)?mb_strtolower(trim($input['username'])):$input['username']??null;
        $input['email']=is_string($input['email']??null)?strtolower(trim($input['email'])):null;
        $data=Validator::make($input,[
            'username'=>['required','string','regex:/^[\p{L}\p{N}_-]{3,32}$/uD',Rule::unique('users','username')->ignore($id)],
            'display_name'=>'required|string|max:64','avatar_url'=>'nullable|string|max:2048','email'=>'nullable|email:rfc|max:254',
            'password'=>[$id===null?'required':'nullable','string'],
        ])->validate();
        if(preg_match('/[\p{C}<>]/u',$data['display_name'])||trim($data['display_name'])===''){throw ValidationException::withMessages(['display_name'=>'Введите имя без управляющих символов и < >.']);}
        $avatar=trim($data['avatar_url']??'')?:null;
        if($avatar){$parts=parse_url($avatar);$host=strtolower($parts['host']??'');
            if(!filter_var($avatar,FILTER_VALIDATE_URL)||preg_match('/\p{C}/u',$avatar)||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||!($host==='cdn.discordapp.com'||$host==='googleusercontent.com'||str_ends_with($host,'.googleusercontent.com'))){throw ValidationException::withMessages(['avatar_url'=>'Нужен HTTPS-адрес аватара Google или Discord.']);}}
        $password=$data['password']??'';
        if($password!==''&&($error=PasswordPolicy::error($password,$data['username']))!==null){throw ValidationException::withMessages(['password'=>$error]);}
        return DB::transaction(function()use($id,$input,$data,$avatar,$password):Player{
            $owner=$this->lock($id,$input);$email=$data['email']?:null;
            if(DB::table('users')->where('username',$data['username'])->when($id,fn($q)=>$q->where('id','<>',$id))->exists()){throw ValidationException::withMessages(['username'=>'Этот логин уже занят.']);}
            if($email&&(DB::table('player_emails')->where('email',$email)->when($id,fn($q)=>$q->where('user_id','<>',$id))->exists()
                ||DB::table('player_identities')->where('verified_email',$email)->when($id,fn($q)=>$q->where('user_id','<>',$id))->exists())){throw ValidationException::withMessages(['email'=>'Этот адрес связан с другим аккаунтом.']);}
            $key=$id??bin2hex(random_bytes(16));
            if(!$owner){DB::table('users')->insert(['id'=>$key,'username'=>$data['username'],'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'created_at'=>time()]);}
            else{
                if($password!=='')$this->credentials->replace($owner,$password);
                $oldEmail=DB::table('player_emails')->where('user_id',$key)->value('email');
                if($owner->username!==$data['username']||$oldEmail!==$email)$this->credentials->revoke($key);
                DB::table('users')->where('id',$key)->update(['username'=>$data['username']]);
            }
            DB::table('player_profiles')->upsert([['user_id'=>$key,'display_name'=>trim($data['display_name']),'avatar_url'=>$avatar,'updated_at'=>time()]],['user_id'],['display_name','avatar_url','updated_at']);
            if($email){$existing=DB::table('player_emails')->where('user_id',$key)->first();DB::table('player_emails')->updateOrInsert(['user_id'=>$key],['email'=>$email,'verified_at'=>$existing?->email===$email?$existing->verified_at:time()]);}
            else DB::table('player_emails')->where('user_id',$key)->delete();
            return Player::findOrFail($key);
        });
    }
    public function delete(string $id,array $input):void
    {
        DB::transaction(function()use($id,$input):void{
            $this->lock($id,$input);
            foreach(['player_password_history','player_password_resets','player_email_challenges','player_emails','player_profiles','player_identities','sessions','user_state'] as $table){DB::table($table)->where('user_id',$id)->delete();}
            DB::table('player_oauth_states')->where('link_user_id',$id)->delete();DB::table('users')->where('id',$id)->delete();
        });
    }
}
