<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Services\DocumentMapEditor;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/** Визуальная правка схем документов с независимыми позициями выходов. */
final class DocumentMapScreen extends Screen
{
    public function name(): string { return 'Редактор карт документов'; }
    public function permission(): array { return ['wiki.content']; }
    // AJAX-редактор сам защищает несохранённую правку; общая форма Orchid здесь не отправляется.
    public function needPreventsAbandonment(): bool { return false; }
    public function query(DocumentMapEditor $editor): array
    { return ['editorMaps'=>array_map(fn($map)=>['id'=>$map['id'],'name'=>$map['name']],$editor->maps())]; }
    public function layout(): array { return [Layout::view('platform.document-map-editor')]; }
}
