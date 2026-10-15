<header class="topbar">
  <button class="icon-button mobile-menu" id="raid-menu" aria-label="Открыть фильтры" aria-expanded="false">☰</button>
  @include('components.brand')
  <nav class="atlas-modes" aria-label="Раздел сайта">
    <a href="index.html">Главная</a>
    <a href="raid.html" aria-current="page">Карты</a>
    <a href="quests.html">Квесты</a>
    <a href="reference.html">Справочник</a>
    <a href="documents.html" id="documents-link">Документы</a>
  </nav>
  <div class="header-right">
    <span class="data-status" id="raid-total">13 локаций</span><button class="button quiet" id="raid-about">Источники</button>
    @include('components.player-navigation')
  </div>
</header>
