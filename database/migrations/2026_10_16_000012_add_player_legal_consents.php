<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Хранит доказательство трёх отдельных подтверждений без новых JSON-колонок. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('player_legal_consents',function(Blueprint $table): void {
            $table->string('user_id',32);$table->string('document',24);$table->string('version',64);
            $table->bigInteger('accepted_at');$table->string('method',16);
            $table->primary(['user_id','document','version']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::table('player_oauth_states',fn(Blueprint $table)=>$table->string('legal_version',64)->nullable());
    }
    public function down(): void
    { throw new \RuntimeException('Историю согласий нельзя удалять откатом миграции.'); }
};
