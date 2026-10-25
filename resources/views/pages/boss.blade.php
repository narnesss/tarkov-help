@extends('layouts.site')
@section('content')
@include('headers.' . $page)
@if (isset($serverContent['entity'])) @include('components.entity-seo') @endif

<main class="boss-page">
  <a class="guide-link" id="boss-back" href="reference.html?tab=bosses">← Справочник</a>
  <header class="boss-heading"><div><span class="eyebrow">ПРОТИВНИКИ В РЕЙДЕ</span><h1 id="boss-title">{{ isset($params['boss']) ? 'Загрузка босса…' : 'Боссы Таркова' }}</h1>@unless (isset($params['boss']))<p id="boss-description">Описание, здоровье по частям тела и места появления. Выберите босса, чтобы открыть его отдельную страницу.</p>@endunless</div><label class="boss-mode">Режим<select id="boss-mode"><option value="pve" selected>PVE</option><option value="regular">PVP</option></select></label></header>
@unless (isset($params['boss']))
  <div class="boss-catalog-controls" id="boss-catalog-controls"><input type="search" id="boss-search" placeholder="Найти босса…" aria-label="Поиск боссов"><span id="boss-count" role="status"></span></div>
@endunless
  <div id="boss-content" aria-live="polite"></div>
</main>
<div class="toast" id="guide-toast" role="status" hidden></div>
  <script type="module" src="bootstrap.mjs?v=f46181ad3223e431"></script>
@endsection
