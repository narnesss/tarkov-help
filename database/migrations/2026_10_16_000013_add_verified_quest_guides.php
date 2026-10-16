<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/** Дополняет существующие квесты, сохраняя ID целей, связи и личный прогресс. */
return new class extends Migration {
    public function up(): void
    {
        $patch=json_decode(file_get_contents(__DIR__.'/data/quest-guides-20261016.json'),true,128,JSON_THROW_ON_ERROR);
        app(\App\Services\QuestGuideUpdate::class)->apply($patch);
    }
    public function down(): void
    { throw new RuntimeException('Дополнения контента не удаляются автоматическим откатом.'); }
};
