<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\ContentDataset;

/** Названия и варианты выбора берутся из редактируемого справочника торговцев. */
final class TraderCatalog
{
    private ?array $records = null;

    public function all(): array
    {
        if ($this->records !== null) { return $this->records; }
        $this->records = [];
        // Список админки не должен распаковывать сотни квестов ради имён торговцев.
        foreach(\Illuminate\Support\Facades\DB::table('wiki_traders')->where('archived',0)->orderBy('name')->get() as $trader){
            $this->records[$trader->external_id]=['id'=>$trader->external_id,'name'=>$trader->name,'imageLink'=>$trader->image_url,'normalizedName'=>$trader->slug];
        }
        if($this->records!==[]){return $this->records;}
        foreach (['guide/quests-regular.json', 'guide/quests-pve.json'] as $path) {
            foreach (ContentDataset::where('path', $path)->first()?->content()['traders'] ?? [] as $trader) {
                if (isset($trader['id'], $trader['name'])) { $this->records[$trader['id']] = $trader; }
            }
        }
        return $this->records;
    }

    public function options(): array { return array_map(fn ($trader) => $trader['name'], $this->all()); }
    public function name(?string $id): string { return $this->all()[$id]['name'] ?? 'Неизвестный торговец'; }
    public function find(string $id): ?array { return $this->all()[$id] ?? null; }
}
