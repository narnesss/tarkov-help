<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Подтверждение почты добавляется без изменения прежних аккаунтов и прогресса. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('player_emails', function (Blueprint $table): void {
            $table->string('user_id',32)->primary();
            $table->string('email',254)->unique();
            $table->bigInteger('verified_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::create('player_email_challenges', function (Blueprint $table): void {
            $table->string('user_id',32)->primary();
            $table->string('challenge_id',32)->unique();
            $table->string('email',254);
            $table->string('code_hash',64);
            $table->unsignedInteger('attempts')->default(0);
            $table->bigInteger('sent_at');
            $table->bigInteger('expires_at')->index();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('player_email_challenges');
        Schema::dropIfExists('player_emails');
    }
};
