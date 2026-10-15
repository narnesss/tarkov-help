<?php
declare(strict_types=1);
namespace App\Models;

/** Аккаунты редакторов Orchid изолированы от opaque-аккаунтов игроков. */
final class User extends \Orchid\Platform\Models\User
{
    protected $table = 'admin_users';
}
