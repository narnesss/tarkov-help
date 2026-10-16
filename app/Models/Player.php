<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Orchid\Screen\AsSource;

/** Игрок сайта; не предоставляет доступ к панели редакторов Orchid. */
final class Player extends Model
{
    use AsSource;
    protected $table='users';public $incrementing=false;protected $keyType='string';public $timestamps=false;
    protected $guarded=['id','password_hash'];protected $hidden=['password_hash'];
}
