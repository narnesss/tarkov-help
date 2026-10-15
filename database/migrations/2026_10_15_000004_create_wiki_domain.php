<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Добавляет предметные связи без изменения контента и прогресса игроков. */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(file_get_contents(__DIR__.'/sql/2026_10_15_domain.sql'));
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'wiki_%'") as $table) {
            Schema::table($table->name, function (Blueprint $table): void {
                $table->string('display_name')->default('');
                $table->unsignedInteger('revision')->default(0);
            });
            if (!Schema::hasColumn($table->name, 'archived')) {
                Schema::table($table->name, fn (Blueprint $table) => $table->boolean('archived')->default(false));
            }
            if (!Schema::hasColumn($table->name, 'search_text')) {
                Schema::table($table->name, fn (Blueprint $table) => $table->text('search_text')->default(''));
            }
        }
        DB::statement('ALTER TABLE wiki_quests ADD COLUMN legacy_quest_id INTEGER REFERENCES quests(id)');
        DB::statement('CREATE UNIQUE INDEX wiki_quests_legacy_unique ON wiki_quests(legacy_quest_id)');
        Schema::table('wiki_items', fn (Blueprint $table) => $table->text('description')->default(''));
        Schema::table('wiki_maps', fn (Blueprint $table) => $table->string('name')->default(''));
        Schema::table('wiki_objectives', function (Blueprint $table): void {
            $table->double('amount')->nullable();$table->string('source_hash')->default('');
        });
        foreach (['wiki_objective_groups','wiki_prerequisite_groups'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('source_path')->nullable());
        }
        Schema::table('wiki_objective_groups', fn (Blueprint $table) => $table->string('purpose')->default('items'));
        Schema::create('wiki_location_aliases', function (Blueprint $table): void {
            $table->id();$table->foreignId('location_id')->constrained('wiki_locations');
            $table->string('scheme');$table->string('value');$table->unique(['scheme','value']);
        });
        Schema::table('wiki_map_points', function (Blueprint $table): void {
            $table->string('source_kind')->default('document');
        });
        Schema::create('wiki_point_world_positions', function (Blueprint $table): void {
            $table->id();$table->foreignId('point_id')->constrained('wiki_map_points');
            $table->double('world_x');$table->double('world_y');$table->double('world_z');
            $table->integer('order_index')->default(0);$table->unique(['point_id','order_index']);
            $table->string('display_name')->default('');$table->text('search_text')->default('');
            $table->unsignedInteger('revision')->default(0);$table->boolean('archived')->default(false);
        });
        Schema::create('wiki_sources', function (Blueprint $table): void {
            $table->id();$table->foreignId('dataset_id')->constrained('content_datasets');$table->string('pointer',2048);
            $table->string('format')->default('object');
            foreach (['item'=>'items','location'=>'locations','map'=>'maps','layer'=>'map_layers','document'=>'documents',
                'point'=>'map_points','trader'=>'traders','skill'=>'skills','station'=>'stations','achievement'=>'achievements','craft'=>'crafts','offer'=>'offers'] as $column=>$target) {
                $table->foreignId($column.'_id')->nullable()->constrained('wiki_'.$target);
            }
            $table->unique(['dataset_id','pointer']);
        });
        Schema::create('wiki_projection_states', function (Blueprint $table): void {
            $table->string('owner_type');$table->unsignedInteger('owner_id');$table->string('content_ref');
            $table->primary(['owner_type','owner_id']);
        });
        Schema::create('wiki_import_issues', function (Blueprint $table): void {
            $table->id();$table->string('scope');$table->string('external_id');$table->string('field');$table->text('message');
            $table->unique(['scope','external_id','field']);
        });
    }
    public function down(): void
    { throw new RuntimeException('Откат запрещён: схема содержит редакторские связи. Используйте согласованную резервную копию.'); }
};
