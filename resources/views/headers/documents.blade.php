<header class="topbar">
  <button class="icon-button mobile-menu" id="menu-button" aria-label="Открыть фильтры" aria-expanded="false">☰</button>
  @include('components.brand')
  <nav class="atlas-modes" aria-label="Раздел сайта">
    <a href="index.html">Главная</a>
    <a href="raid.html" id="raid-link">Карты</a>
    <a href="quests.html">Квесты</a>
    <a href="reference.html">Справочник</a>
    <a href="documents.html" aria-current="page">Документы</a>
  </nav>
  <div class="header-right">
    <span class="data-status"><i></i><span id="dataset-count">Загрузка данных</span></span>
    <button class="button quiet progress-open" id="progress-button"><svg><use href="#i-progress"/></svg><span>Прогресс</span><b id="total-found">0</b></button>
    <button class="icon-button" id="about-button" aria-label="О карте и источниках" title="О карте и источниках"><svg><use href="#i-info"/></svg></button>
    <button class="button profile-trigger" id="profile-button" aria-haspopup="dialog">Мой профиль</button>
  </div>
</header>
