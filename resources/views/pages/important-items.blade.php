@extends('layouts.site')
@section('content')
@include('headers.important-items')
<main class="important-items-layout">
  <nav class="important-breadcrumbs" aria-label="Хлебные крошки"><a href="/">Главная</a><span aria-hidden="true"> / </span><span>Важные предметы</span></nav>
  <header><p class="eyebrow">СОХРАНИТЬ ДЛЯ ДАЛЬНЕЙШЕГО</p><h1>Важные предметы</h1><p class="important-help">Предметы для квестов и постройки убежища. Количества указаны отдельно для каждого требования; варианты на выбор не нужно собирать все сразу.</p></header>
  @if ($listing === null)
  <p role="status">Список доступен при подключённом сервере данных. <a href="/important-items">Повторить</a></p>
  @else
  <form class="important-filters" method="get" action="/important-items">
    <label class="important-search">Поиск<input name="q" type="search" maxlength="160" value="{{ $listing['query'] }}" placeholder="Название предмета…" aria-describedby="important-search-error"><span class="important-field-error" id="important-search-error" role="alert" hidden></span></label>
    <label>Режим<select name="mode"><option value="pve" @selected($listing['mode'] === 'pve')>PVE</option><option value="regular" @selected($listing['mode'] === 'regular')>PVP</option></select></label>
    <label>Нужен для<select name="kind"><option value="all" @selected($listing['kind'] === 'all')>Квестов и убежища</option><option value="quest" @selected($listing['kind'] === 'quest')>Квестов</option><option value="hideout" @selected($listing['kind'] === 'hideout')>Убежища</option></select></label>
    <label>Условие<select name="fir"><option value="all" @selected($listing['fir'] === 'all')>Любые предметы</option><option value="fir" @selected($listing['fir'] === 'fir')>Найти в рейде</option></select></label>
    <button class="button primary" type="submit">Показать</button><a class="button" href="/important-items?mode={{ $listing['mode'] }}">Сбросить</a>
  </form>
  <p class="important-help">Найдено: {{ $listing['total'] }} · Страница {{ $listing['page'] }} из {{ $listing['pages'] }}. Список переключается целыми страницами.</p>
  <div class="important-grid" data-important-mode="{{ $listing['mode'] }}">
  @forelse ($listing['rows'] as $item)
    <article class="important-card">
      <header><a class="important-item" href="/reference/items/{{ rawurlencode($item['id']) }}?mode={{ $listing['mode'] }}">@if ($item['icon'] !== '')<img src="{{ $item['icon'] }}" alt="" width="64" height="64" loading="lazy" decoding="async">@endif<h2>{{ $item['name'] }}</h2></a></header>
      @if ($item['craftable'])<a class="important-badge" href="/reference/crafts?mode={{ $listing['mode'] }}">Можно изготовить в убежище</a>@endif
      <ul class="important-uses">
      @foreach ($item['uses'] as $use)
        <li data-important-kind="{{ $use['kind'] }}" data-important-id="{{ $use['id'] }}">
          <div class="important-use-heading"><strong>× {{ number_format($use['count'], 0, ',', ' ') }}</strong><a href="{{ $use['kind'] === 'quest' ? '/quests/'.rawurlencode($use['id']).'?mode='.$listing['mode'] : '/reference/hideout?mode='.$listing['mode'] }}">{{ $use['name'] }}</a></div>
          <div class="important-tags"><span>{{ $use['kind'] === 'quest' ? 'Квест' : 'Убежище' }}@if ($use['level'] > 0) · {{ $use['kind'] === 'quest' ? 'ур. игрока' : 'уровень' }} {{ $use['level'] }}@endif</span>
          @if ($use['foundInRaid'])<span class="important-badge">Найти в рейде</span>@endif
          @if ($use['optional'])<span>Необязательная цель</span>@endif
          @if ($use['alternative'])<span>Один из вариантов</span>@endif
          <span class="important-complete" hidden></span></div>
        </li>
      @endforeach
      </ul>
    </article>
  @empty
    <p role="status">Подходящих предметов нет. Измените поиск или фильтры.</p>
  @endforelse
  </div>
  <nav class="important-pagination" aria-label="Страницы важных предметов">
    @if ($listing['page'] > 1)<a class="button" rel="prev" href="{{ \App\Services\ImportantItems::href($listing, $listing['page'] - 1) }}">← Назад</a>@endif
    @for ($number = 1; $number <= $listing['pages']; $number++)
      @if ($number === 1 || $number === $listing['pages'] || abs($number - $listing['page']) <= 2)
        <a class="button" href="{{ \App\Services\ImportantItems::href($listing, $number) }}" @if ($number === $listing['page']) aria-current="page" @endif aria-label="Страница {{ $number }}">{{ $number }}</a>
      @elseif (abs($number - $listing['page']) === 3)<span aria-hidden="true">…</span>@endif
    @endfor
    @if ($listing['page'] < $listing['pages'])<a class="button" rel="next" href="{{ \App\Services\ImportantItems::href($listing, $listing['page'] + 1) }}">Далее →</a>@endif
  </nav>
  @endif
</main>
<script type="module" src="bootstrap.mjs?v=loading-20261018"></script>
<script type="module" src="important-items-form.mjs?v=20261017"></script>
@endsection
