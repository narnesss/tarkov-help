<?php
declare(strict_types=1);
namespace App\Orchid\Screens;
use App\Models\Player;
use App\Services\PlayerAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Screen;
use Orchid\Screen\Actions\{Button,Link};
use Orchid\Screen\Fields\Input;
use Orchid\Support\Facades\{Layout,Toast};

/** Создаёт и редактирует игроков без ручных ID, JSON и подмены их прогресса. */
final class PlayerEditScreen extends Screen
{
    protected ?Player $record=null;
    public function name():string{return $this->record?->exists?'Редактирование пользователя':'Новый пользователь';}
    public function permission():array{return ['wiki.users'];}
    public function query(Player $player,PlayerAdmin $editor):array
    {
        abort_if($player->exists&&$player->username===null,404);$this->record=$player;
        $profile=$player->exists?DB::table('player_profiles')->where('user_id',$player->id)->first():null;
        $providers=$player->exists?DB::table('player_identities')->where('user_id',$player->id)->orderBy('provider')->pluck('provider')->map(fn($p)=>ucfirst($p))->implode(', '):'';
        return ['player'=>['revision'=>$player->exists?$editor->revision($player->id):'',
            'username'=>$player->username,'display_name'=>$profile?->display_name??$profile?->provider_name??$player->username,
            'avatar_url'=>$profile?->avatar_url,'email'=>$player->exists?DB::table('player_emails')->where('user_id',$player->id)->value('email'):null,
            'providers'=>$providers?:'Не подключены','progress'=>$player->exists?DB::table('user_state')->where('user_id',$player->id)->count():0]];
    }
    public function commandBar():array
    {
        return [Link::make('К списку')->route('platform.players'),Button::make('Сохранить')->icon('bs.check')->method('save'),
            Button::make('Удалить пользователя')->icon('bs.trash')->method('remove')->novalidate()->canSee((bool)$this->record?->exists)
                ->confirm('Пользователь, его способы входа и весь прогресс будут удалены. Продолжить?')];
    }
    public function layout():array
    {
        return [Layout::view('platform.player-validation'),Layout::rows([
            Input::make('player.revision')->type('hidden'),Input::make('player.username')->title('Логин')->required()->minlength(3)->maxlength(32),
            Input::make('player.display_name')->title('Отображаемое имя')->required()->maxlength(64),
            Input::make('player.avatar_url')->title('Аватар — HTTPS-адрес Google или Discord')->type('url')->maxlength(2048),
            Input::make('player.email')->title('Подтверждённый e-mail')->type('email')->maxlength(254)->help('Используется для восстановления и автопривязки. Указывайте только проверенный адрес.'),
            Input::make('player.password')->title($this->record?->exists?'Новый пароль (необязательно)':'Пароль')->type('password')->autocomplete('new-password')->minlength(12)->maxlength(72)->required(!$this->record?->exists)
                ->help('12+ символов: заглавная и строчная латинские буквы, цифра и спецсимвол. Без популярных комбинаций и прежних паролей. Смена пароля, логина или почты завершает действующие сеансы.'),
            Input::make('player.providers')->title('Подключённые способы входа')->readonly(),Input::make('player.progress')->title('Сохранённых записей прогресса')->readonly(),
        ])];
    }
    public function save(Request $request,Player $player,PlayerAdmin $editor)
    {
        $input=$request->validate(['player'=>'required|array'],['player.required'=>'Заполните форму пользователя.','player.array'=>'Некорректный формат формы пользователя.'])['player'];
        try{$saved=$editor->save($player->exists?$player->id:null,$input);}
        catch(ValidationException $e){$errors=[];foreach($e->errors() as $field=>$messages)$errors['player.'.$field]=$messages;throw ValidationException::withMessages($errors);}
        Toast::info('Пользователь сохранён.');return redirect()->route('platform.players.edit',$saved);
    }
    public function remove(Request $request,Player $player,PlayerAdmin $editor)
    {
        abort_unless($player->exists,404);$input=$request->validate(['player'=>'required|array'],['player.required'=>'Заполните форму пользователя.','player.array'=>'Некорректный формат формы пользователя.'])['player'];$editor->delete($player->id,$input);Toast::info('Пользователь и связанные данные удалены.');return redirect()->route('platform.players');
    }
}
