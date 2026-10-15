<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Производный индекс названий; игровые идентификаторы не вводятся в формах. */
class ContentReference extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];
}
