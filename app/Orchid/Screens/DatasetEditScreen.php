<?php
declare(strict_types=1);
namespace App\Orchid\Screens;

use App\Models\ContentDataset;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Screen;
use Orchid\Screen\Actions\{Button, Link};
use Orchid\Screen\Fields\TextArea;
use Orchid\Support\Facades\{Layout, Toast};

/** Сохраняет справочник в БД; имя набора и публичный URL не меняются. */
final class DatasetEditScreen extends Screen
{
    public function name(): string { return 'Редактирование данных'; }
    public function permission(): array { return ['wiki.content']; }
    public function query(ContentDataset $dataset): array
    { return ['content' => $dataset->json()]; }
    public function commandBar(): array
    { return [Button::make('Сохранить')->method('save'), Link::make('К списку')->route('platform.datasets')]; }
    public function layout(): array
    { return [Layout::rows([TextArea::make('content')->title('Данные (JSON)')->rows(35)->required()->help('Сохраняйте структуру набора и ID объектов, которые используются страницами сайта.')])]; }
    public function save(Request $request, ContentDataset $dataset)
    {
        $input = $request->validate(['content' => ['required', 'string', 'max:30000000']]);
        try { $data = json_decode($input['content'], true, 128, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw ValidationException::withMessages(['content' => 'Некорректный JSON.']); }
        if (!is_array($data)) { throw ValidationException::withMessages(['content' => 'Ожидается JSON-объект или массив.']); }
        $dataset->setContent($data); $dataset->save();
        Toast::info('Данные сохранены.');
        return redirect()->route('platform.datasets');
    }
}
