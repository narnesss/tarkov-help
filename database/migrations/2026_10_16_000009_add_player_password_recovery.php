<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Восстановление меняет только пароль прежнего игрока, не создавая новый аккаунт. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('player_password_resets', function(Blueprint $table): void {
            $table->string('user_id',32)->primary();
            $table->string('challenge_id',32)->unique();
            $table->string('email',254);
            $table->bigInteger('email_verified_at');
            $table->string('session_hash',64);
            $table->string('credential_hash',64);
            $table->string('code_hash',64);
            $table->unsignedInteger('attempts')->default(0);
            $table->bigInteger('sent_at');
            $table->bigInteger('expires_at')->index();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('player_password_resets'); }
};
