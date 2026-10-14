<header class="topbar">
  @include('components.brand')
  <nav class="atlas-modes" aria-label="Раздел сайта">
    <a href="index.html">Главная</a>
    <a href="raid.html">Карты</a>
    <a href="quests.html" aria-current="page">Квесты</a>
    <a href="reference.html">Справочник</a>
    <a href="documents.html">Документы</a>
  </nav>
  <div class="header-right">
    <label class="header-game-mode"><span class="sr-only">Игровой режим</span><select id="header-quest-mode" aria-label="Игровой режим"><option value="pve">PVE</option><option value="regular">PVP</option></select></label>
    <button id="guide-sources" class="button quiet">Источники</button>
    <button class="button profile-trigger" id="profile-button" aria-haspopup="dialog">Мой профиль</button>
  </div>
</header>
