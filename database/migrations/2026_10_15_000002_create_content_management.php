<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Добавляет контент и админку, не меняя таблицы игроков. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable(); $table->string('password');
            $table->text('permissions')->nullable(); $table->rememberToken(); $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('slug')->unique();
            $table->text('permissions')->nullable(); $table->timestamps();
        });
        Schema::create('role_users', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });
        Schema::create('admin_sessions', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable(); $table->text('user_agent')->nullable();
            $table->longText('payload'); $table->integer('last_activity')->index();
        });
        Schema::create('content_datasets', function (Blueprint $table): void {
            $table->id(); $table->string('path')->unique(); $table->longText('payload'); $table->timestamps();
        });
        Schema::create('quests', function (Blueprint $table): void {
            $table->id(); $table->string('quest_id'); $table->string('mode');
            $table->string('name'); $table->string('trader_id')->nullable();
            $table->boolean('published')->default(true); $table->longText('payload'); $table->timestamps();
            $table->unique(['quest_id', 'mode']); $table->index(['mode', 'published']);
        });
    }

    public function down(): void
    { throw new RuntimeException('Откат запрещён: таблицы содержат редакторские данные. Используйте резервную копию.'); }
};
