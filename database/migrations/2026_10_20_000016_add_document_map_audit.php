<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Закрытый журнал редактора без payload, контактных данных и каскадного удаления событий. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wiki_document_map_audit', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('actor_ref',64);
            $table->unsignedBigInteger('point_id')->nullable();
            $table->string('entity_type',32);
            $table->string('result',16);
            $table->string('revision_before',64)->nullable();
            $table->string('revision_after',64)->nullable();
            $table->string('changed_fields',100);
            $table->bigInteger('recorded_at');
            $table->string('previous_hash',64);
            $table->string('signature',64);
        });
    }
    public function down(): void { Schema::dropIfExists('wiki_document_map_audit'); }
};
