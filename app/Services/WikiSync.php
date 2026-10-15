<?php
declare(strict_types=1);
namespace App\Services;

/** Предотвращает повторный импорт при публикации SQLite-связей в совместимые файлы. */
final class WikiSync
{
    public bool $busy = false;
    public function quest(\App\Models\Quest $quest): void
    {
        if(!$this->busy&&\Illuminate\Support\Facades\DB::table('wiki_projection_states')->where('owner_type','full')->exists()){
            $this->run(fn()=>\Illuminate\Support\Facades\DB::transaction(fn()=>app(WikiImporter::class)->quest($quest)));
        }
    }
    public function dataset(\App\Models\ContentDataset $dataset): void
    {
        if(!$this->busy&&\Illuminate\Support\Facades\DB::table('wiki_projection_states')->where('owner_type','full')->exists()){
            $this->run(fn()=>\Illuminate\Support\Facades\DB::transaction(fn()=>app(WikiImporter::class)->dataset($dataset)));
        }
    }
    public function run(callable $callback): mixed
    {
        $previous=$this->busy;$this->busy=true;
        try { return $callback(); } finally { $this->busy=$previous; }
    }
}
