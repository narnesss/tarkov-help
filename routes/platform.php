<?php
declare(strict_types=1);

use App\Orchid\Screens\{QuestListScreen, QuestEditScreen, QuestFieldsScreen, DatasetListScreen, DatasetEditScreen};
use Illuminate\Support\Facades\Route;

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
