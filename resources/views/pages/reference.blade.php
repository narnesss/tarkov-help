@extends('layouts.site')
@section('content')
@include('headers.' . $page)

<main class="reference-layout">
<header class="reference-toolbar">
  <span class="eyebrow">ПОЛЕВОЙ СПРАВОЧНИК</span><div class="reference-toolbar-title"><h1>Справочник</h1><p>Снаряжение, рецепты и ориентиры для следующего рейда.</p></div>
  <div class="reference-filters">
    <label class="reference-search-label">Поиск<input type="search" id="reference-search" placeholder="Найти предмет, босса или рецепт…" aria-label="Поиск в справочнике"></label>
    <label class="reference-mode">Режим<select id="reference-mode"><option value="pve" selected>PVE</option><option value="regular">PVP</option></select></label>
    <label id="reference-filter-label">Категория<select id="reference-filter" aria-label="Категория"></select></label>
    <label id="reference-sort-label">Сортировка<select id="reference-sort" aria-label="Сортировка"></select></label>
  </div>
</header>
<aside class="reference-nav-panel" aria-label="Навигация справочника"><h2>Разделы</h2><nav class="reference-tabs" id="reference-tabs" aria-label="Раздел справочника"></nav></aside>
<section class="reference-main" aria-label="Выбранный раздел">
  <header class="reference-heading"><div><nav class="reference-breadcrumbs" aria-label="Путь к разделу"><span>Справочник</span><span>›</span><span id="reference-crumb"></span></nav><h2 id="reference-title">Загрузка…</h2><p id="reference-description"></p></div><span id="reference-count" class="quest-tag" aria-label="Количество результатов"></span></header>
  <p id="reference-data-date" class="reference-help" role="status"></p><div id="reference-content"></div><div class="reference-pager"><button class="button" id="reference-prev">← Назад</button><span id="reference-page"></span><button class="button" id="reference-next">Далее →</button></div>
</section></main>
<dialog class="dialog item-dialog" id="item-dialog"><div class="dialog-heading"><h2 id="item-title"></h2><button class="icon-button" id="item-close" aria-label="Закрыть предмет">×</button></div><div id="item-body" class="item-dialog-body"></div></dialog><div class="toast" id="guide-toast" role="status" hidden></div>
  <script type="module" src="bootstrap.mjs?v=legal-2"></script>
@endsection
