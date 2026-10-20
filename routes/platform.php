<?php
declare(strict_types=1);

use App\Orchid\Screens\{QuestListScreen, QuestEditScreen, QuestFieldsScreen, DatasetListScreen, DatasetEditScreen};
use Illuminate\Support\Facades\Route;

Route::middleware(\App\Http\Middleware\ProtectAdminRoute::class)->group(function (): void {
Route::get('document-maps/data',[\App\Http\Controllers\DocumentMapEditorController::class,'data'])->name('platform.document-maps.data');
Route::get('document-maps/audit',[\App\Http\Controllers\DocumentMapEditorController::class,'audit'])->name('platform.document-maps.audit');
Route::post('document-maps/save',[\App\Http\Controllers\DocumentMapEditorController::class,'save'])->name('platform.document-maps.save');
Route::post('document-maps/remove',[\App\Http\Controllers\DocumentMapEditorController::class,'remove'])->name('platform.document-maps.remove');
Route::get('document-maps/artwork',[\App\Http\Controllers\DocumentMapEditorController::class,'artwork'])->name('platform.document-maps.artwork');
Route::screen('document-maps',\App\Orchid\Screens\DocumentMapScreen::class)->name('platform.document-maps');
Route::get('map-editor/data',[\App\Http\Controllers\RaidMapEditorController::class,'data'])->name('platform.map-editor.data');
Route::get('map-editor/items',[\App\Http\Controllers\RaidMapEditorController::class,'items'])->name('platform.map-editor.items');
Route::post('map-editor/points',[\App\Http\Controllers\RaidMapEditorController::class,'save'])->middleware('throttle:60,1')->name('platform.map-editor.save');
Route::screen('map-editor',\App\Orchid\Screens\MapEditorScreen::class)->name('platform.map-editor');
Route::screen('players/create',\App\Orchid\Screens\PlayerEditScreen::class)->name('platform.players.create');
Route::screen('players/{player}/edit',\App\Orchid\Screens\PlayerEditScreen::class)->where('player','[a-f0-9]{32}')->name('platform.players.edit');
Route::screen('players',\App\Orchid\Screens\PlayerListScreen::class)->name('platform.players');

Route::screen('wiki', \App\Orchid\Screens\WikiHomeScreen::class)->name('platform.wiki');
Route::screen('wiki/{type}/create', \App\Orchid\Screens\WikiEditScreen::class)->name('platform.wiki.create');
Route::screen('wiki/{type}/{record}/edit', \App\Orchid\Screens\WikiEditScreen::class)->where('record','[0-9]+')->name('platform.wiki.edit');
Route::screen('wiki/{type}', \App\Orchid\Screens\WikiListScreen::class)->name('platform.wiki.list');

Route::screen('quests/create', QuestEditScreen::class)->name('platform.quests.create');
Route::screen('quests/{quest}/edit', QuestEditScreen::class)->name('platform.quests.edit');
Route::screen('quests/{quest}/fields', QuestFieldsScreen::class)->name('platform.quests.fields');
Route::screen('quests', QuestListScreen::class)->name('platform.quests');
Route::screen('datasets/{dataset}/edit', DatasetEditScreen::class)->name('platform.datasets.edit');
Route::screen('datasets', DatasetListScreen::class)->name('platform.datasets');
});
