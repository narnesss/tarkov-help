<?php
declare(strict_types=1);
namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Validator;

/** Нормализует только редактируемый текст; секреты, подписи и стабильные ID не трогает. */
final class AdminInput
{
    public static function trim(mixed $value): mixed
    { return is_string($value) ? preg_replace('/^\s+|\s+$/u', '', $value) ?? $value : $value; }

    public static function urlField(string $field): bool
    {
        $parts=explode('/',trim($field,'/'));$leaf=(string)array_pop($parts);
        return (bool)preg_match('/(?:url|link)$/i',$leaf)||in_array($leaf,['image','file'],true)
            ||(ctype_digit($leaf)&&in_array(end($parts),['images','gallery'],true));
    }

    public static function rules(string $field, int $max=100000): array
    {
        return ['bail','string','max:'.$max,static function(string $attribute,mixed $value,Closure $fail) use($field): void {
            if(!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|<\s*(?:[!\/?a-zA-Z])[^>]*>/u',$value)){
                $fail('Введите обычный текст без HTML и управляющих символов.');return;
            }
            if(self::urlField($field)&&$value!==''&&!self::safeUrl($value)){$fail('Укажите HTTP(S)-ссылку или относительный путь без опасных символов.');}
        }];
    }

    public static function safeUrl(string $value): bool
    {
        if(preg_match('/[\s\p{C}<>"\'\\\\]/u',$value)||str_starts_with($value,'//')){return false;}
        if(preg_match('/^[a-z][a-z0-9+.-]*:/i',$value)){
            $url=parse_url($value);
            return filter_var($value,FILTER_VALIDATE_URL)!==false&&is_array($url)
                &&in_array(strtolower($url['scheme']??''),['http','https'],true)&&!isset($url['user'])&&!isset($url['pass']);
        }
        return !str_contains(explode('/',$value,2)[0],':');
    }

    public static function finite(): Closure
    {
        return static function(string $attribute,mixed $value,Closure $fail): void {
            if(!is_numeric($value)||!is_finite((float)$value)){$fail('Введите конечное число.');}
        };
    }

    public static function messages(): array
    {
        return ['required'=>'Заполните это поле.','present'=>'Поле должно присутствовать в форме.',
            'string'=>'Введите текстовое значение.','array'=>'Некорректная структура поля.',
            'integer'=>'Введите целое число.','numeric'=>'Введите число.','boolean'=>'Выберите да или нет.',
            'in'=>'Выберите значение из списка.','max.string'=>'Допустимо не более :max символов.',
            'max.array'=>'Допустимо не более :max записей.','min.numeric'=>'Минимальное значение: :min.',
            'between.numeric'=>'Значение должно быть от :min до :max.'];
    }

    /** Поиск допускает произвольный текст: безопасность обеспечивают параметры SQL и экранирование вывода. */
    public static function search(mixed $value,string $field='search'): string
    {
        $value=self::trim($value??'');
        $data=Validator::make([$field=>$value],[$field=>['bail','string','max:255',static function($attribute,$value,$fail): void {
            if(!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1F\x7F]/u',$value)){$fail('Некорректная строка поиска.');}
        }]],self::messages())->validate();
        return $data[$field];
    }
}
