<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

/** Сериализует проверку и запись графа редакторов при нескольких MySQL-подключениях. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wiki_write_locks',function(Blueprint $table): void {$table->unsignedInteger('id')->primary();});
        DB::table('wiki_write_locks')->insert(['id'=>1]);
    }
    public function down(): void { throw new RuntimeException('Служебная блокировка нужна работающим редакторам.'); }
};
