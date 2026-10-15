<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Добавляет способы входа, сохраняя прежние ID игроков и прогресс. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('player_identities', function (Blueprint $table): void {
            $table->string('provider', 16); $table->string('subject', 255);
            $table->string('user_id', 32); $table->string('display_name', 255);
            $table->bigInteger('created_at');
            $table->primary(['provider', 'subject']);
            $table->unique(['user_id', 'provider']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::create('player_oauth_states', function (Blueprint $table): void {
            $table->string('token_hash', 64)->primary();
            $table->string('session_hash', 64); $table->string('provider', 16);
            $table->string('link_user_id', 32)->nullable();
            $table->bigInteger('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_oauth_states');
        Schema::dropIfExists('player_identities');
    }
};
