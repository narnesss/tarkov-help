<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\ValidationException;

/** Фиксирует отдельные волеизъявления; не меняет ID, профиль и прогресс игрока. */
final class LegalConsents
{
    public const DOCUMENTS=['terms','privacy','personal-data'];
    public function version(): string
    {
        $content=json_encode([config('legal.operator'),config('legal.edition')],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        foreach([...self::DOCUMENTS,'cookies'] as $page){$content.="\0".str_replace("\r\n","\n",file_get_contents(resource_path('views/pages/'.$page.'.blade.php')));}
        $content.="\0".str_replace("\r\n","\n",file_get_contents(resource_path('views/components/legal-operator.blade.php')));
        return hash('sha256',$content);
    }
    public function hasCurrent(?string $userId): bool
    { return $userId!==null&&DB::table('player_legal_consents')->where('user_id',$userId)->where('version',$this->version())->whereIn('document',self::DOCUMENTS)->count()===count(self::DOCUMENTS); }
    public function publicData(?string $userId=null): array
    { return ['required'=>(bool)config('legal.required'),'version'=>$this->version(),'accepted'=>$this->hasCurrent($userId)]; }
    public function accept(array $input): ?string
    {
        if(!config('legal.required')){return null;}
        $checked=static function($attribute,$value,$fail): void {if($value!==true){$fail('Подтвердите это согласие самостоятельно.');}};
        $data=Validator::make($input,['legal'=>['required','array:version,terms,privacy,personal_data'],
            'legal.version'=>['bail','required','string','size:64'],
            'legal.terms'=>['required',$checked],'legal.privacy'=>['required',$checked],'legal.personal_data'=>['required',$checked]],
            ['required'=>'Подтвердите условия, ознакомление с политикой и отдельное согласие на обработку данных.',
                'array'=>'Некорректные сведения о согласии.','size'=>'Обновите страницу с документами.','string'=>'Некорректная версия документов.'])->validate();
        $this->checkVersion($data['legal']['version']);return $data['legal']['version'];
    }
    public function checkVersion(?string $version): void
    {
        if(config('legal.required')&&(!is_string($version)||!hash_equals($this->version(),$version))){
            throw ValidationException::withMessages(['legal.version'=>'Документы обновились. Ознакомьтесь с новой редакцией и подтвердите согласия ещё раз.']);
        }
    }
    public function record(string $userId,?string $version,string $method): void
    {
        $this->checkVersion($version);if($version===null){return;}
        foreach(self::DOCUMENTS as $document){DB::table('player_legal_consents')->insertOrIgnore([
            'user_id'=>$userId,'document'=>$document,'version'=>$version,'accepted_at'=>time(),'method'=>$method]);}
    }
}
