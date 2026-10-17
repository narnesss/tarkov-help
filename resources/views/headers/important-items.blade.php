<header class="topbar">
  @include('components.brand')
  <nav class="atlas-modes" aria-label="Раздел сайта">
    <a href="/">Главная</a><a href="/raid">Карты</a><a href="/documents">Документы</a><a href="/quests">Квесты</a><a href="/reference">Справочник</a><a href="/important-items" aria-current="page">Важные предметы</a>
  </nav>
  <div class="header-right">
  @include('components.game-clock')@include('components.player-navigation')</div>
</header>
