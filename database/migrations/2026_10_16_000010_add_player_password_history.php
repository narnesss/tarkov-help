<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Сохраняет только хеши заменённых паролей без изменения существующих аккаунтов. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('player_password_history',function(Blueprint $table):void {
            $table->id();$table->string('user_id',32);$table->string('password_hash',255);$table->bigInteger('created_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();$table->index('user_id');
        });
    }
    public function down(): void {throw new RuntimeException('Откат запрещён: история предотвращает повторное использование паролей.');}
};
