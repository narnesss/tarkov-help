@extends('layouts.site')
@section('content')
@include('headers.' . $page)

<main class="boss-page">
  <a class="guide-link" id="boss-back" href="reference.html?tab=bosses">← Справочник</a>
  <header class="boss-heading"><div><span class="eyebrow">ПРОТИВНИКИ В РЕЙДЕ</span><h1 id="boss-title">Боссы Таркова</h1><p>Описание, здоровье по частям тела и места появления. Выберите босса, чтобы открыть его отдельную страницу.</p></div><label class="boss-mode">Режим<select id="boss-mode"><option value="pve" selected>PVE</option><option value="regular">PVP</option></select></label></header>
  <div class="boss-catalog-controls" id="boss-catalog-controls"><input type="search" id="boss-search" placeholder="Найти босса…" aria-label="Поиск боссов"><span id="boss-count" role="status"></span></div>
  <div id="boss-content" aria-live="polite"></div>
</main>
<div class="toast" id="guide-toast" role="status" hidden></div>
  <script type="module" src="bootstrap.mjs?v=account-1"></script>
@endsection
