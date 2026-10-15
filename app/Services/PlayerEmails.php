<?php
declare(strict_types=1);
namespace App\Services;

use App\Mail\VerifyPlayerEmail;
use EftWiki\Identity\Session;
use Illuminate\Support\Facades\{DB, Mail};
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Подтверждённый контакт принадлежит прежнему ID; код хранится только как HMAC. */
final class PlayerEmails
{
    public function enabled(): bool
    {
        $mailer=(string)config('player-auth.email.mailer');
        $transport=config('mail.mailers.'.$mailer.'.transport');
        return in_array($transport,['smtp','sendmail','ses','postmark','resend','mailgun'],true)
            || ($transport==='array' && app()->environment('testing'));
    }

    private function named(Session $session): void { abort_unless($session->username!==null,403,'Сначала войдите в аккаунт.'); }

    public function status(Session $session): array
    {
        $this->named($session);
        $confirmed=DB::table('player_emails')->where('user_id',$session->userId)->first();
        $pending=DB::table('player_email_challenges')->where('user_id',$session->userId)->where('expires_at','>',time())->first();
        return ['enabled'=>$this->enabled(),'email'=>$confirmed?->email,'verifiedAt'=>$confirmed?->verified_at,
            'pending'=>$pending?['email'=>$pending->email,'challengeId'=>$pending->challenge_id,'expiresAt'=>(int)$pending->expires_at,
                'resendAt'=>(int)$pending->sent_at+60,'attemptsLeft'=>max(0,5-(int)$pending->attempts)]:null];
    }

    private function available(string $email,string $owner): bool
    {
        return !DB::table('player_emails')->where('email',$email)->where('user_id','!=',$owner)->exists()
            && !DB::table('player_identities')->where('verified_email',$email)->where('user_id','!=',$owner)->exists();
    }

    private function throttle(string $key,int $limit,int $window): void
    {
        $bucket='email:'.hash('sha256',$key).':'.intdiv(time(),$window);
        $count=(int)DB::table('rate_limits')->where('bucket',$bucket)->value('count');
        abort_if($count>=$limit,429,'Слишком много писем. Попробуйте позже.');
        DB::table('rate_limits')->updateOrInsert(['bucket'=>$bucket],['count'=>$count+1,'expires_at'=>time()+$window]);
    }

    private function digest(string $owner,string $challenge,string $email,string $code): string
    { return hash_hmac('sha256',$owner.'|'.$challenge.'|'.$email.'|'.$code,(string)config('app.key')); }

    public function send(Session $session,string $email,string $ip): array
    {
        $this->named($session);
        abort_unless($this->enabled(),503,'Отправка писем временно недоступна.');
        $email=strtolower(trim($email));
        abort_unless(strlen($email)<=254 && filter_var($email,FILTER_VALIDATE_EMAIL),422,'Введите корректный e-mail.');
        $challenge=bin2hex(random_bytes(16)); $code=str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT);
        $sent=DB::transaction(function() use($session,$email,$ip,$challenge,$code): bool {
            DB::table('player_auth_locks')->where('id',1)->lockForUpdate()->first();
            abort_unless($this->available($email,$session->userId),409,'Адрес уже связан с другим аккаунтом. Войдите в него, чтобы сохранить его прогресс.');
            if(DB::table('player_emails')->where('user_id',$session->userId)->where('email',$email)->exists()){return false;}
            $previous=DB::table('player_email_challenges')->where('user_id',$session->userId)->first();
            abort_if($previous && (int)$previous->sent_at>time()-60,429,'Новое письмо можно запросить через минуту.');
            $this->throttle('user:'.$session->userId,5,3600); $this->throttle('ip:'.$ip,10,3600); $this->throttle('address:'.$email,3,3600);
            DB::table('player_email_challenges')->where('expires_at','<=',time())->delete();
            DB::table('player_email_challenges')->updateOrInsert(['user_id'=>$session->userId],['challenge_id'=>$challenge,'email'=>$email,
                'code_hash'=>$this->digest($session->userId,$challenge,$email,$code),'attempts'=>0,'sent_at'=>time(),'expires_at'=>time()+600]);
            return true;
        });
        if($sent){
            try {
                Mail::mailer((string)config('player-auth.email.mailer'))->to($email)->send((new VerifyPlayerEmail($code))
                    ->from((string)config('player-auth.email.from'),'Tarkov Compass'));
            } catch(\Throwable) {
                DB::table('player_email_challenges')->where('user_id',$session->userId)->where('challenge_id',$challenge)->delete();
                throw new HttpException(503,'Не удалось отправить письмо. Попробуйте позже.');
            }
        }
        return [...$this->status($session),'sent'=>$sent];
    }

    public function verify(Session $session,string $challenge,string $code): array
    {
        $this->named($session);
        $result=DB::transaction(function() use($session,$challenge,$code): int {
            DB::table('player_auth_locks')->where('id',1)->lockForUpdate()->first();
            $pending=DB::table('player_email_challenges')->where('user_id',$session->userId)->where('challenge_id',$challenge)->first();
            if(!$pending || (int)$pending->expires_at<=time()){return 410;}
            if((int)$pending->attempts>=5){return 429;}
            if(!hash_equals($pending->code_hash,$this->digest($session->userId,$challenge,$pending->email,$code))){
                DB::table('player_email_challenges')->where('user_id',$session->userId)->increment('attempts');return 422;
            }
            if(!$this->available($pending->email,$session->userId)){return 409;}
            DB::table('player_emails')->updateOrInsert(['user_id'=>$session->userId],['email'=>$pending->email,'verified_at'=>time()]);
            DB::table('player_email_challenges')->where('user_id',$session->userId)->delete();return 200;
        });
        abort_unless($result===200,$result,match($result){410=>'Код истёк или уже использован. Запросите новое письмо.',429=>'Попытки исчерпаны. Запросите новый код.',
            409=>'Адрес уже связан с другим аккаунтом. Войдите в него.',default=>'Неверный код подтверждения.'});
        return $this->status($session);
    }
}
