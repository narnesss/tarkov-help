@extends('layouts.site')
@section('content')
@include('headers.' . $page)

<main class="quest-layout" id="quest-layout" data-view="quest">
<article class="quest-detail" id="quest-detail"><p class="guide-empty" role="status">Загрузка задания…</p></article>
</main>
<dialog class="dialog" id="guide-sources-dialog"><div class="dialog-heading"><h2>Справочные материалы</h2><button class="icon-button" id="guide-sources-close" aria-label="Закрыть">×</button></div><ul class="source-list"><li><a href="https://tarkov.help/ru" target="_blank" rel="noopener noreferrer">Tarkov.help — прохождения ↗</a></li><li><a href="https://escapefromtarkov.fandom.com/ru/wiki/Escape_from_Tarkov_Wiki" target="_blank" rel="noopener noreferrer">Русская Wiki ↗</a></li></ul><p class="muted">Неофициальный справочник. Ваши отметки не связаны с игровым аккаунтом.</p></dialog><div class="toast" id="guide-toast" role="status" hidden></div>
  <script type="module" src="bootstrap.mjs?v=loading-20261018"></script>
@endsection
