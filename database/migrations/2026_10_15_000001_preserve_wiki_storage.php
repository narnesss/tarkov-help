<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Создаёт совместимую схему или принимает прежнюю БД без удаления прогресса. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->string('id', 32)->primary(); $table->string('username')->nullable()->unique();
                $table->string('password_hash')->nullable(); $table->integer('created_at');
            });
        }
        if (!Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table): void {
                $table->string('token_hash', 64)->primary(); $table->string('user_id', 32);
                $table->integer('expires_at')->index(); $table->foreign('user_id')->references('id')->on('users');
            });
        }
        if (!Schema::hasTable('user_state')) {
            Schema::create('user_state', function (Blueprint $table): void {
                $table->string('user_id', 32); $table->string('state_key'); $table->text('value');
                $table->integer('version')->default(1); $table->integer('updated_at');
                $table->primary(['user_id', 'state_key']); $table->foreign('user_id')->references('id')->on('users');
            });
        }
        if (!Schema::hasTable('rate_limits')) {
            Schema::create('rate_limits', function (Blueprint $table): void {
                $table->string('bucket')->primary(); $table->integer('count'); $table->integer('expires_at');
            });
        }
    }

    public function down(): void
    { throw new \RuntimeException('Откат запрещён: таблицы содержат личный прогресс. Используйте резервную копию.'); }
};
