<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Добавляет необязательные сведения без изменения аккаунтов и прогресса. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->string('phone', 16)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('gender', 16)->nullable();
            $table->unsignedTinyInteger('age')->nullable();
        });
        Schema::create('player_activity', function (Blueprint $table): void {
            $table->string('user_id',32)->primary();
            $table->bigInteger('last_active_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_activity');
        Schema::table('player_profiles', fn (Blueprint $table) => $table->dropColumn([
            'phone', 'country', 'city', 'gender', 'age',
        ]));
    }
};
