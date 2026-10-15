<?php
declare(strict_types=1);
namespace App\Models\Wiki;

use Illuminate\Database\Eloquent\Model;
use Orchid\Screen\AsSource;

/** Общая Eloquent-модель типизированных таблиц, включая составные ключи связей SQLite. */
class Record extends Model
{
    use AsSource;
    public $timestamps = false;
    protected $primaryKey = 'rowid';
    protected $guarded = ['rowid','id'];
    protected static function booted(): void
    {
        if(static::class!==self::class&&static::class!==Options::class){
            static::addGlobalScope('visible',fn($query)=>$query->where($query->getModel()->qualifyColumn('archived'),0));
        }
    }
}
