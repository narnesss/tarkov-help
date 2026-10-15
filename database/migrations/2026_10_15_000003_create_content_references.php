<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Поисковый справочник связей: только обычные поля, содержимое остаётся в файлах. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('content_references', function (Blueprint $table): void {
            $table->id();$table->string('kind', 32);$table->string('variant', 64)->default('default');
            $table->string('value', 160);$table->string('label', 512);$table->text('search_text');
            $table->foreignId('source_dataset_id')->nullable()->constrained('content_datasets')->cascadeOnDelete();
            $table->string('source_pointer', 2048)->default('');
            $table->foreignId('quest_record_id')->nullable()->constrained('quests')->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->unique(['kind', 'variant', 'value']);$table->index(['kind', 'variant', 'active']);
        });
    }
    public function down(): void
    { Schema::dropIfExists('content_references'); }
};
