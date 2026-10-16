<?php
declare(strict_types=1);
namespace App\Orchid\Screens;
use App\Models\Player;
use Illuminate\Http\Request;
use Orchid\Screen\{Screen,TD};
use Orchid\Screen\Actions\{Link,Button};
use Orchid\Screen\Fields\Input;
use Orchid\Support\Facades\Layout;

/** Именованный каталог зарегистрированных пользователей сайта. */
final class PlayerListScreen extends Screen
{
    public function name():string{return 'Пользователи';}
    public function description():string{return 'Аккаунты сайта и их постоянные профили. Доступ редакторов к админке настраивается отдельно.';}
    public function permission():array{return ['wiki.users'];}
    public function query():array
    {
        $search=request()->query('search','');$search=is_string($search)?mb_substr($search,0,100):'';
        $players=Player::query()->leftJoin('player_profiles as p','p.user_id','=','users.id')->leftJoin('player_emails as e','e.user_id','=','users.id')
            ->whereNotNull('users.username')->select('users.id','users.username','users.created_at','p.display_name','p.provider_name','e.email')
            ->when($search!=='',fn($q)=>$q->where(fn($q)=>$q->where('users.username','like','%'.$search.'%')->orWhere('p.display_name','like','%'.$search.'%')->orWhere('e.email','like','%'.$search.'%')))
            ->orderByDesc('users.created_at')->orderBy('users.id')->paginate(30)->withQueryString();
        return ['search'=>$search,'players'=>$players];
    }
    public function commandBar():array{return [Link::make('Добавить пользователя')->icon('bs.plus')->route('platform.players.create')];}
    public function layout():array
    {
        return [Layout::rows([Input::make('search')->title('Поиск по имени, логину или почте')->type('search'),Button::make('Найти')->method('filter')]),
            Layout::table('players',[
                TD::make('display_name','Имя')->render(fn(Player $p)=>Link::make($p->display_name??$p->provider_name??$p->username)->route('platform.players.edit',$p)),
                TD::make('username','Логин'),TD::make('email','Подтверждённая почта'),
                TD::make('created_at','Регистрация')->render(fn(Player $p)=>e(date('d.m.Y H:i',(int)$p->created_at))),
            ])];
    }
    public function filter(Request $request){return redirect()->route('platform.players',$request->only('search'));}
}
