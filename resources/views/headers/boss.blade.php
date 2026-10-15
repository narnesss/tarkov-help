<header class="topbar">
  @include('components.brand')
  <nav class="atlas-modes" aria-label="Раздел сайта">
    <a href="index.html">Главная</a>
    <a href="raid.html">Карты</a>
    <a href="quests.html">Квесты</a>
    <a href="reference.html" aria-current="page">Справочник</a>
    <a href="documents.html">Документы</a>
  </nav>
  <div class="header-right">
    <a class="button quiet" id="boss-guide" href="reference.html?tab=bosses">Справочник</a>
    @include('components.player-navigation')
  </div>
</header>
