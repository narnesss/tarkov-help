<?php
declare(strict_types=1);
namespace App\Services;

use App\Mail\ResetPlayerPassword;
use EftWiki\Identity\Session;
use EftWiki\Identity\PasswordPolicy;
use Illuminate\Support\Facades\{DB, Mail, Log};
use function Illuminate\Support\defer;

/** Одноразовое восстановление по подтверждённому контакту без замены ID и прогресса. */
final readonly class PlayerPasswordRecovery
{
    public const INVALID_CODE='Код неверен, истёк или уже использован. При необходимости запроси новый код.';
    public function __construct(private PlayerEmails $emails) {}

    private function digest(string $owner,string $challenge,string $email,string $sessionHash,string $code): string
    { return hash_hmac('sha256','password-reset|'.$owner.'|'.$challenge.'|'.$email.'|'.$sessionHash.'|'.$code,(string)config('app.key')); }

    private function throttle(string $key,int $limit): bool
    {
        $bucket='password-reset:'.hash('sha256',$key).':'.intdiv(time(),3600);
        $count=(int)DB::table('rate_limits')->where('bucket',$bucket)->value('count');
        if($count>=$limit){return false;}
        DB::table('rate_limits')->updateOrInsert(['bucket'=>$bucket],['count'=>$count+1,'expires_at'=>time()+3600]);return true;
    }

    public function request(Session $session,string $email,string $ip): array
    {
        abort_unless($this->emails->enabled(),503,'Отправка писем временно недоступна.');
        $email=strtolower(trim($email));
        abort_unless(strlen($email)<=254 && filter_var($email,FILTER_VALIDATE_EMAIL),422,'Введи корректный e-mail.');
        $challenge=bin2hex(random_bytes(16));$code=str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT);
        $sessionHash=hash('sha256',$session->token);$now=time();
        $accepted=DB::transaction(function()use($email,$ip,$challenge,$code,$sessionHash,$now): bool {
            DB::table('player_auth_locks')->where('id',1)->lockForUpdate()->first();
            // Лимиты одинаковы для существующих, неизвестных и ещё не подтверждённых адресов.
            if(!$this->throttle('ip:'.$ip,10)||!$this->throttle('email:'.$email,3)){return false;}
            $cooldown='password-reset:cooldown:'.hash('sha256',$email);
            if((int)DB::table('rate_limits')->where('bucket',$cooldown)->value('expires_at')>$now){return false;}
            DB::table('rate_limits')->updateOrInsert(['bucket'=>$cooldown],['count'=>1,'expires_at'=>$now+60]);
            DB::table('player_password_resets')->where('expires_at','<=',$now)->delete();
            $contact=DB::table('player_emails')->join('users','users.id','=','player_emails.user_id')->where('email',$email)
                ->whereNotNull('username')->select('player_emails.*','users.password_hash')->first();
            if($contact){
                DB::table('player_password_resets')->updateOrInsert(['user_id'=>$contact->user_id],[
                    'challenge_id'=>$challenge,'email'=>$email,'email_verified_at'=>$contact->verified_at,'session_hash'=>$sessionHash,
                    'credential_hash'=>hash('sha256',(string)$contact->password_hash),'code_hash'=>$this->digest($contact->user_id,$challenge,$email,$sessionHash,$code),
                    'attempts'=>0,'sent_at'=>$now,'expires_at'=>$now+3600]);
            }
            return true;
        });
        abort_unless($accepted,429,'Новое письмо можно запросить через минуту. При частых запросах попробуй позже.');
        // Даже проверка наличия запроса и SMTP выполняются после HTTP-ответа.
        defer(fn()=>$this->deliver($challenge,$email,$code));
        return ['challengeId'=>$challenge,'expiresAt'=>$now+3600,'resendAt'=>$now+60,
            'message'=>'Если эта почта подтверждена в аккаунте, мы отправим на неё код восстановления. Проверь также папку «Спам».'];
    }

    private function deliver(string $challenge,string $email,string $code): void
    {
        $lock=null;
        try{
            $path=DatabaseAccess::configuredPath();
            if(str_starts_with($path,'/')){$lock=(new DatabaseAccess($path))->acquire();if($lock===null){return;}}
            $pending=DB::table('player_password_resets')->where('challenge_id',$challenge)->where('expires_at','>',time())->first();
            if(!$pending){return;}
            Mail::mailer((string)config('player-auth.email.mailer'))->to($email)->send((new ResetPlayerPassword($code))
                ->from((string)config('player-auth.email.from'),'Tarkov Compass'));
        }catch(\Throwable){
            // В HTTP-ответе, журнале и очереди не появляются адрес, код или ошибка SMTP с реквизитами.
            try{DB::table('player_password_resets')->where('challenge_id',$challenge)->delete();}catch(\Throwable){}
            Log::warning('Не удалось отправить письмо восстановления пароля.');
        }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
    }

    public function reset(Session $session,string $challenge,string $code,string $password): array
    {
        if(($error=PasswordPolicy::error($password))!==null){abort(422,$error);}
        $username=DB::transaction(function()use($session,$challenge,$code,$password): ?string {
            DB::table('player_auth_locks')->where('id',1)->lockForUpdate()->first();
            $sessionHash=hash('sha256',$session->token);
            $pending=DB::table('player_password_resets')->where('challenge_id',$challenge)->where('session_hash',$sessionHash)->first();
            if(!$pending||(int)$pending->expires_at<=time()||(int)$pending->attempts>=5){return null;}
            if(!DB::table('sessions')->where('token_hash',$sessionHash)->where('expires_at','>',time())->exists()){return null;}
            if(!hash_equals($pending->code_hash,$this->digest($pending->user_id,$challenge,$pending->email,$sessionHash,$code))){
                DB::table('player_password_resets')->where('challenge_id',$challenge)->increment('attempts');return null;
            }
            $contact=DB::table('player_emails')->where('user_id',$pending->user_id)->first();
            $owner=DB::table('users')->where('id',$pending->user_id)->lockForUpdate()->first();
            if(!$contact||!$owner||$owner->username===null||$contact->email!==$pending->email||(int)$contact->verified_at!==(int)$pending->email_verified_at
                ||!hash_equals($pending->credential_hash,hash('sha256',(string)$owner->password_hash))){return null;}
            if(($error=PasswordPolicy::error($password,$owner->username))!==null){abort(422,$error);}
            $history=DB::table('player_password_history')->where('user_id',$owner->id)->pluck('password_hash')->all();
            foreach([$owner->password_hash,...$history] as $hash){
                abort_if(is_string($hash)&&password_verify($password,$hash),422,'Этот пароль уже использовался в аккаунте. Придумай новый.');
            }
            if(is_string($owner->password_hash)){
                DB::table('player_password_history')->insert(['user_id'=>$owner->id,'password_hash'=>$owner->password_hash,'created_at'=>time()]);
            }
            DB::table('users')->where('id',$pending->user_id)->update(['password_hash'=>password_hash($password,PASSWORD_DEFAULT)]);
            DB::table('sessions')->where('user_id',$pending->user_id)->delete();
            DB::table('player_oauth_states')->where('link_user_id',$pending->user_id)->delete();
            DB::table('player_email_challenges')->where('user_id',$pending->user_id)->delete();
            DB::table('player_password_resets')->where('user_id',$pending->user_id)->delete();
            return $owner->username;
        });
        abort_if($username===null,422,self::INVALID_CODE);
        return ['username'=>$username,'message'=>'Пароль изменён. Войди в прежний аккаунт с новым паролем. Прогресс сохранён.'];
    }
}
