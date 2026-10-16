<?php
declare(strict_types=1);
namespace EftWiki\Identity;

/** Правила для новых паролей; вход со старым паролем остаётся совместимым. */
final class PasswordPolicy
{
    public static function error(string $password,string $username=''): ?string
    {
        if(!mb_check_encoding($password,'UTF-8')||mb_strlen($password)<12||strlen($password)>72||preg_match('/\p{C}/u',$password)){
            return 'Пароль: минимум 12 символов и максимум 72 байта, без управляющих символов.';
        }
        if(!preg_match('/[A-Z]/',$password)||!preg_match('/[a-z]/',$password)||!preg_match('/[0-9]/',$password)||!preg_match('/[\p{P}\p{S}]/u',$password)){
            return 'Добавь заглавную и строчную латинские буквы, цифру и спецсимвол.';
        }
        $lower=mb_strtolower($password);
        $plain=strtr($lower,['@'=>'a','0'=>'o','1'=>'i','3'=>'e','4'=>'a','5'=>'s','7'=>'t','$'=>'s','!'=>'i']);
        foreach(['password','qwerty','asdfgh','zxcvbn','letmein','welcome','admin','iloveyou','пароль','tarkov'] as $word){
            if(str_contains($lower,$word)||str_contains($plain,$word)){return 'Не используй распространённые слова и комбинации вроде password, qwerty или 123456.';}
        }
        foreach(['0123456789','abcdefghijklmnopqrstuvwxyz','qwertyuiop','asdfghjkl','zxcvbnm'] as $row){
            foreach([$row,strrev($row)] as $sequence){for($i=0;$i<=strlen($sequence)-6;$i++){
                if(str_contains($lower,substr($sequence,$i,6))){return 'Не используй последовательности из 6 символов и длиннее.';}
            }}
        }
        if(preg_match('/(.)\1{5}/us',$password)||preg_match('/(?:(?:0[1-9]|[12][0-9]|3[01])[-.\/]?(?:0[1-9]|1[0-2])[-.\/]?(?:19|20)[0-9]{2}|(?:19|20)[0-9]{2}[-.\/]?(?:0[1-9]|1[0-2])[-.\/]?(?:0[1-9]|[12][0-9]|3[01]))/',$password)){
            return 'Не используй даты и длинные повторы одинаковых символов.';
        }
        $name=mb_strtolower($username);
        if(mb_strlen($name)>=4&&str_contains($lower,$name)){return 'Пароль не должен содержать твой логин.';}
        return null;
    }
}
