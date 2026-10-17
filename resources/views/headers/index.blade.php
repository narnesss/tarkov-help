<header class="topbar">
  @include('components.brand')
  <nav class="atlas-modes" aria-label="Раздел сайта">
    <a href="index.html" aria-current="page">Главная</a>
    <a href="raid.html">Карты</a>
    <a href="documents.html">Документы</a>
    <a href="quests.html">Квесты</a>
    <a href="reference.html">Справочник</a>
  </nav>
  <div class="header-right">
    @include('components.player-navigation')
  </div>
</header>
