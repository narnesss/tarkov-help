<header class="topbar">
  @include('components.brand')
  <nav class="atlas-modes" aria-label="Раздел сайта">
    <a href="index.html">Главная</a>
    <a href="raid.html">Карты</a>
    <a href="documents.html">Документы</a>
    <a href="quests.html" aria-current="page">Квесты</a>
    <a href="reference.html">Справочник</a><a href="/important-items">Важные предметы</a>
  </nav>
  <div class="header-right">
  @include('components.game-clock')
    <label class="header-game-mode"><span class="sr-only">Игровой режим</span><select id="header-quest-mode" aria-label="Игровой режим"><option value="pve">PVE</option><option value="regular">PVP</option></select></label>
    <button id="guide-sources" class="button quiet">Источники</button>
    @include('components.player-navigation')
  </div>
</header>
