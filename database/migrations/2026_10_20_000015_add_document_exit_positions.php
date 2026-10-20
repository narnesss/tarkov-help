<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Независимые позиции выходов на схемах документов, без изменения игровых координат. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wiki_document_exit_positions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('point_id');
            $table->unsignedBigInteger('map_id');
            $table->unsignedBigInteger('layer_id');
            $table->double('x_percent');
            $table->double('y_percent');
            $table->unsignedBigInteger('revision')->default(0);
            $table->unique(['point_id', 'map_id'], 'document_exit_point_map_unique');
            $table->foreign('point_id')->references('id')->on('wiki_map_points')->cascadeOnDelete();
            $table->foreign('map_id')->references('id')->on('wiki_maps')->cascadeOnDelete();
            $table->foreign(['layer_id', 'map_id'], 'document_exit_layer_map_fk')->references(['id', 'map_id'])->on('wiki_map_layers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_document_exit_positions');
    }
};
