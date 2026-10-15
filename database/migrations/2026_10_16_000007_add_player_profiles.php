<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Отделяет отображение профиля от неизменных ID и логинов игроков. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('player_profiles', function (Blueprint $table): void {
            $table->string('user_id', 32)->primary();
            $table->string('display_name', 64)->nullable();
            $table->string('provider_name', 64)->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->bigInteger('updated_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::table('player_identities', function (Blueprint $table): void {
            $table->string('verified_email', 254)->nullable()->index();
            $table->boolean('email_auto_link')->default(false);
        });
        Schema::create('player_auth_locks', function (Blueprint $table): void { $table->unsignedInteger('id')->primary(); });
        DB::table('player_auth_locks')->insert(['id'=>1]);
        // Прежние OAuth-имена уже известны: не нужен повторный вход для замены технического логина.
        foreach (DB::table('player_identities')->orderBy('created_at')->orderByDesc('provider')->get() as $identity) {
            DB::table('player_profiles')->insertOrIgnore(['user_id'=>$identity->user_id,'display_name'=>null,
                'provider_name'=>\App\Services\PlayerProfiles::providerName($identity->display_name), 'avatar_url'=>null,'updated_at'=>time()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('player_auth_locks');
        Schema::table('player_identities', function (Blueprint $table): void {
            $table->dropIndex(['verified_email']); $table->dropColumn(['verified_email','email_auto_link']);
        });
        Schema::dropIfExists('player_profiles');
    }
};
