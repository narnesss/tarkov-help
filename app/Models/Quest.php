<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Orchid\Screen\AsSource;

/** ID заданий и целей остаются прежними для совместимости личного прогресса. */
final class Quest extends Model
{
    use AsSource;
    protected $guarded = ['id'];
    protected $casts = ['payload' => 'array', 'published' => 'boolean'];
}
