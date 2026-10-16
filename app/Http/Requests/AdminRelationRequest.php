<?php
declare(strict_types=1);
namespace App\Http\Requests;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Orchid\Platform\Http\Requests\RelationRequest;

/** Отсекает неверные типы и повреждённые параметры поиска до контроллера Orchid. */
final class AdminRelationRequest extends RelationRequest
{
    public function rules(): array
    {
        return [...parent::rules(),'search'=>'nullable|string|max:255','chunk'=>'sometimes|integer|between:1,30',
            'append'=>'nullable|string|max:4096','model'=>'required|string|max:4096','name'=>'required|string|max:4096',
            'key'=>'required|string|max:4096','scope'=>'nullable|string|max:16384','searchColumns'=>'nullable|string|max:16384'];
    }
    protected function passedValidation(): void
    {
        if(array_diff_key($this->all(),$this->rules())){throw ValidationException::withMessages(['relation'=>'Недопустимые параметры поиска.']);}
        foreach(['model','name','key','scope','append','searchColumns'] as $field){
            if($this->input($field)===null){continue;}
            try{in_array($field,['scope','searchColumns'],true)?Crypt::decrypt($this->input($field)):Crypt::decryptString($this->input($field));}
            catch(DecryptException){throw ValidationException::withMessages([$field=>'Параметры поиска устарели или повреждены. Обновите страницу.']);}
        }
    }
}
