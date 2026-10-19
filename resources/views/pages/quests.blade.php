@extends('layouts.site')
@section('content')
@include('headers.' . $page)

<main class="quest-layout" id="quest-layout" data-view="hub">

<section class="quest-toolbar" aria-label="Поиск и фильтры квестов">
<nav id="quest-catalog-breadcrumbs" class="quest-breadcrumbs" aria-label="Путь по каталогу"></nav>
<span class="eyebrow quest-journal-label">ЖУРНАЛ ОПЕРАТОРА</span>
<div class="quest-toolbar-title"><h1 id="quest-catalog-title">Квесты</h1><span class="guide-count" id="quest-count"></span><button id="quest-mobile-filters" class="button quest-mobile-control" aria-expanded="false" aria-controls="quest-filter-panel" disabled>Поиск и фильтры</button></div>
<p id="quest-catalog-description" class="quest-catalog-description">Выберите торговца или главу основного сюжета, чтобы открыть задания.</p>
<div id="quest-filter-panel" class="quest-filter-panel">
<div class="quest-primary-filters">
<label class="quest-search-label"><span class="sr-only">Поиск квестов</span><input type="search" id="quest-search" placeholder="Найти квест или цель…" aria-label="Поиск квестов"></label>
<label>Режим<select id="quest-mode"><option value="pve" selected>PVE</option><option value="regular">PVP</option></select></label>
<label>Локация<select id="quest-map" aria-label="Локация"><option value="all">Все локации</option></select></label>
<label>Статус<select id="quest-status" aria-label="Статус"><option value="all">Все задания</option><option value="available">Доступны по цепочке</option><option value="active">В процессе</option><option value="remaining">Не выполнены</option><option value="complete">Выполнены</option><option value="locked">Ещё закрыты</option><option value="failed">Провалены</option></select></label>
<details class="quest-filter-more"><summary>Ещё</summary><div class="quest-secondary-filters">
<label>Направление<select id="quest-flag" aria-label="Назначение"><option value="all">Все направления</option><option value="favorite">Избранное</option><option value="kappa">Для Каппы</option><option value="lightkeeper">Для Смотрителя</option></select></label>
<label>Сортировка<select id="quest-sort" aria-label="Порядок"><option value="level">По уровню</option><option value="name">По названию</option><option value="xp">По опыту</option></select></label>
<label>Уровень<input id="player-level" type="number" min="1" max="100" value="1"></label>
<label>Фракция<select id="player-faction"><option value="any">Любая</option><option>BEAR</option><option>USEC</option></select></label>
<div class="quest-progress"><div><strong id="quest-done">—</strong><span id="quest-total"> / … выполнено</span></div><progress id="quest-progress" value="0" max="1" aria-label="Прогресс квестов"></progress></div>
<div class="guide-side-foot"><div class="guide-button-row"><button class="button" id="quest-export">Скачать прогресс</button><button class="button" id="quest-import">Загрузить</button><input id="quest-import-file" type="file" accept=".json,application/json" hidden></div><small>Личные отметки и настройки сохраняются в вашем профиле. У PVE и PVP отдельный прогресс.</small><button class="text-button" id="quest-refresh">Обновить квесты ↻</button><span id="quest-data-date" role="status">Загрузка данных…</span></div>
</div></details>
<button id="quest-reset" class="button quest-reset" type="button" title="Сбросить поиск и фильтры"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10a9 9 0 1 1 2.5 8M3 4v6h6"/></svg><span>Сбросить</span></button>
</div>
</div></section>
<section id="quest-hub" class="quest-hub" aria-label="Разделы квестов">
<div id="quest-story-card"></div>
<div class="quest-hub-heading"><div><span class="eyebrow">ЗАДАНИЯ ТОРГОВЦЕВ</span><h2>Выберите торговца</h2></div><div class="quest-catalog-shortcuts"><a id="quest-kappa-link" class="button" href="quests.html?section=all&amp;flag=kappa&amp;mode=pve">Для Каппы · …</a><a id="quest-all-link" class="button quiet" href="quests.html?section=all&amp;mode=pve">Все задания ↗</a></div></div>
<div class="guide-traders quest-trader-grid" id="quest-traders" aria-label="Торговцы"></div>
<a id="quest-archive-link" class="text-button quest-archive-link" href="quests.html?section=archive&amp;mode=pve">Другие и архивные</a>
</section>
<section class="quest-directory" aria-label="Список квестов">
<div class="quest-directory-top"><strong id="quest-list-title">Задания</strong><span>Откройте квест для подробного прохождения</span></div>
<div class="quest-table-heading" aria-hidden="true"><span>Квест</span><span>Цели</span><span>Награды</span><span>Мой прогресс</span><span>Каппа</span></div>
<div id="quest-list" class="quest-list" role="list"></div><button class="button quest-more" id="quest-more" hidden>Показать ещё</button>
</section>
<article class="quest-detail" id="quest-detail"><div class="guide-empty" id="quest-loading" role="status">Загружаем журнал заданий…</div></article>
</main>
<dialog class="quest-mobile-filter-dialog" id="quest-filters-dialog" aria-label="Поиск и фильтры квестов"><div class="quest-filter-dialog-heading"><h2>Поиск и фильтры</h2><button id="quest-filters-close" class="button quest-mobile-control" type="button">Готово</button></div></dialog>
<dialog class="dialog" id="guide-sources-dialog"><div class="dialog-heading"><h2>Справочные материалы</h2><button class="icon-button" id="guide-sources-close" aria-label="Закрыть">×</button></div><ul class="source-list"><li><a href="https://tarkov.help/ru" target="_blank" rel="noopener noreferrer">Tarkov.help — прохождения ↗</a></li><li><a href="https://escapefromtarkov.fandom.com/ru/wiki/Escape_from_Tarkov_Wiki" target="_blank" rel="noopener noreferrer">Русская Wiki ↗</a></li></ul><p class="muted">Неофициальный справочник. Ваши отметки не связаны с игровым аккаунтом.</p></dialog><div class="toast" id="guide-toast" role="status" hidden></div>
  <script type="module" src="bootstrap.mjs?v=documents-exits-20261020"></script>
@endsection
