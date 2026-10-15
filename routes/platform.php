<?php
declare(strict_types=1);

use App\Orchid\Screens\{QuestListScreen, QuestEditScreen, QuestFieldsScreen, DatasetListScreen, DatasetEditScreen};
use Illuminate\Support\Facades\Route;

Route::screen('quests/create', QuestEditScreen::class)->name('platform.quests.create');
Route::screen('quests/{quest}/edit', QuestEditScreen::class)->name('platform.quests.edit');
Route::screen('quests/{quest}/fields', QuestFieldsScreen::class)->name('platform.quests.fields');
Route::screen('quests', QuestListScreen::class)->name('platform.quests');
Route::screen('datasets/{dataset}/edit', DatasetEditScreen::class)->name('platform.datasets.edit');
Route::screen('datasets', DatasetListScreen::class)->name('platform.datasets');
