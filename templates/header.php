<header class="topbar">
<?php if (in_array($page, ['index', 'raid'], true)): ?>
  <button class="icon-button mobile-menu" id="<?= $page === 'index' ? 'menu-button' : 'raid-menu' ?>" aria-label="Открыть фильтры" aria-expanded="false">☰</button>
<?php endif; ?>
  <a class="brand" href="index.html" aria-label="Escape from Tarkov Wiki — главная">
    <img class="site-logo" src="assets/site-logo.svg" width="40" height="40" alt="">
    <span class="brand-wordmark">ESCAPE FROM TARKOV<span class="brand-light">WIKI</span><small>КАРТЫ · ЗАДАНИЯ · СПРАВОЧНИК</small></span>
  </a>
  <nav class="atlas-modes" aria-label="Раздел сайта">
<?php foreach (['index' => 'Документы', 'raid' => 'Интерактивные карты', 'quests' => 'Квесты', 'reference' => 'Справочник'] as $target => $label): ?>
    <a href="<?= $target ?>.html"<?= ($page === $target || ($page === 'boss' && $target === 'reference') || ($page === 'quest' && $target === 'quests')) ? ' aria-current="page"' : '' ?><?= $target === 'index' && $page === 'raid' ? ' id="documents-link"' : '' ?><?= $target === 'raid' && $page === 'index' ? ' id="raid-link"' : '' ?>><?= $label ?></a>
<?php endforeach; ?>
  </nav>
  <div class="header-right">
<?php if ($page === 'index'): ?>
    <span class="data-status"><i></i><span id="dataset-count">Загрузка данных</span></span>
    <button class="button quiet progress-open" id="progress-button"><svg><use href="#i-progress"/></svg><span>Прогресс</span><b id="total-found">0</b></button>
    <button class="icon-button" id="about-button" aria-label="О карте и источниках" title="О карте и источниках"><svg><use href="#i-info"/></svg></button>
<?php elseif ($page === 'raid'): ?>
    <span class="data-status" id="raid-total">13 локаций</span><button class="button quiet" id="raid-about">Источники</button>
<?php elseif (in_array($page, ['quests', 'quest'], true)): ?>
    <label class="header-game-mode"><span class="sr-only">Игровой режим</span><select id="header-quest-mode" aria-label="Игровой режим"><option value="pve">PVE</option><option value="regular">PVP</option></select></label>
    <button id="guide-sources" class="button quiet">Источники</button>
<?php elseif ($page === 'boss'): ?>
    <a class="button quiet" id="boss-guide" href="reference.html?tab=bosses">Справочник</a>
<?php endif; ?>
    <button class="button profile-trigger" id="profile-button" aria-haspopup="dialog">Мой профиль</button>
  </div>
</header>
