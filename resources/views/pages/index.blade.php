@extends('layouts.site')
@section('content')
<svg class="svg-sprite" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
  <symbol id="home-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></symbol>
  <symbol id="home-search-icon" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></symbol>
  <symbol id="home-map" viewBox="0 0 24 24"><path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2V5Zm6-2v16m6-14v16"/></symbol>
  <symbol id="home-quest" viewBox="0 0 24 24"><path d="M8 4H5v17h14V4h-3M8 2h8v5H8V2Zm0 10h8m-8 4h5"/></symbol>
  <symbol id="home-item" viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5ZM3 8v9l9 5 9-5V8m-9 5v9m-5-16 9 5"/></symbol>
  <symbol id="home-ammo" viewBox="0 0 24 24"><path d="M7 3C5 5 4 7 4 9v11h6V9c0-2-1-4-3-6Zm10 0c-2 2-3 4-3 6v11h6V9c0-2-1-4-3-6ZM4 15h6m4 0h6"/></symbol>
  <symbol id="home-boss" viewBox="0 0 24 24"><path d="M6 3h12l3 6-3 9-6 4-6-4-3-9 3-6Zm0 7 4 2m8-2-4 2m-6 5h8m-4-3v4"/></symbol>
  <symbol id="home-hideout" viewBox="0 0 24 24"><path d="m2 11 10-8 10 8M5 9v12h14V9m-10 12v-7h6v7"/></symbol>
  <symbol id="home-doc" viewBox="0 0 24 24"><path d="M5 3h9l5 5v13H5V3Zm9 0v5h5M9 12h6m-6 4h6"/></symbol>
  <symbol id="home-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/><circle cx="12" cy="12" r="10"/></symbol>
</defs></svg>
@include('headers.' . $page)

<main class="home-shell" id="home-main">
  <section class="home-hero" aria-labelledby="home-title">
    <div class="home-hero-copy">
      <span class="home-kicker"><i aria-hidden="true"></i> ТВОЙ СПРАВОЧНИК ПО ESCAPE FROM TARKOV</span>
      <h1 id="home-title">Всё для<br>следующего <span>рейда.</span></h1>
      <p>Найди нужный квест, изучи локацию и подготовь снаряжение. Карты, прохождения и твой прогресс — в одном месте.</p>
      <div class="home-hero-actions"><a class="button primary" href="raid.html?artwork=source&amp;mode=pve" data-home-link="raid.html?artwork=source">Открыть карты <svg aria-hidden="true"><use href="#home-arrow"/></svg></a><a class="button quiet" href="quests.html?section=all&amp;mode=pve" data-home-link="quests.html?section=all">Квесты торговцев</a></div>
      <div class="home-stats"><div><strong id="home-map-total">—</strong><span>интерактивных карт</span></div><div><strong id="home-quest-total">—</strong><span>задание в каталоге</span></div><div><strong id="home-kappa-total">—</strong><span>задание для Каппы</span></div></div>
    </div>
    <a id="home-map-preview" class="home-map-preview" href="raid.html?map=streets-of-tarkov&amp;mode=pve" data-home-link="raid.html?map=streets-of-tarkov" aria-label="Открыть интерактивную карту Улиц Таркова">
      <img src="assets/maps/streets.webp" alt="Фрагмент карты Улиц Таркова" width="1784" height="1376" fetchpriority="high">
      <div class="home-map-grid" aria-hidden="true"></div><div class="home-map-crosshair" aria-hidden="true"><i></i></div>
      <div class="home-map-top"><span>КАРТА ЛОКАЦИИ</span><span id="home-map-position">—</span></div>
      <div class="home-map-caption"><div><small>ИЗУЧИ МЕСТНОСТЬ</small><strong>Улицы Таркова</strong><span>Квестовые места · Выходы · Лут</span></div><span class="home-map-open"><svg aria-hidden="true"><use href="#home-arrow"/></svg></span></div>
      <span class="home-map-credit">Карта: Jindouz</span>
    </a>
  </section>
  <section class="home-search-section" aria-label="Поиск по справочнику">
    <div class="home-search-top"><label for="home-search">Что ищем?</label><div class="home-mode" role="group" aria-label="Игровой режим"><button type="button" data-mode="pve" aria-pressed="true">PVE</button><button type="button" data-mode="regular" aria-pressed="false">PVP</button></div></div>
    <form class="home-search-form" id="home-search-form" role="search" action="quests.html" method="get"><svg aria-hidden="true"><use href="#home-search-icon"/></svg><input id="home-search" name="q" type="search" placeholder="Квест, предмет, босс или локация…" autocomplete="off" aria-controls="home-search-results"><input type="hidden" name="section" value="all"><input type="hidden" name="mode" value="pve" id="home-search-mode"><button type="submit" class="button">Найти <svg aria-hidden="true"><use href="#home-arrow"/></svg></button></form>
    <div class="home-search-results" id="home-search-results" hidden><p id="home-search-status" role="status"></p><div id="home-search-list"></div></div>
    <div class="home-search-hints"><span>Быстрый доступ</span><a href="quests.html?section=all&amp;flag=kappa&amp;mode=pve" data-home-link="quests.html?section=all&amp;flag=kappa">Для Каппы</a><a href="quests.html?section=story&amp;mode=pve" data-home-link="quests.html?section=story">Основной сюжет</a><a href="reference.html?tab=needed&amp;mode=pve" data-home-link="reference.html?tab=needed">Что собирать</a><a href="reference.html?tab=ammo&amp;mode=pve" data-home-link="reference.html?tab=ammo">Патроны</a></div>
  </section>
  <section class="home-directory" aria-labelledby="home-directory-title">
    <div class="home-section-heading"><div><span class="home-kicker">ОТ ПЛАНА ДО ВЫХОДА</span><h2 id="home-directory-title">Подготовка к рейду</h2></div><p>Выбери, с чего начать.</p></div>
    <div class="home-directory-grid">
          <a class="home-tool-card home-tool-primary" href="quests.html?section=all&amp;mode=pve" data-home-link="quests.html?section=all"><span class="home-tool-icon"><svg aria-hidden="true"><use href="#home-quest"/></svg></span><span class="home-card-number">01</span><h3>Задания</h3><p>Торговцы, цепочки, цели и награды. Прохождения с картами и фотографиями.</p><span class="home-card-link">Открыть журнал <svg aria-hidden="true"><use href="#home-arrow"/></svg></span></a>
          <a class="home-tool-card home-tool-primary" href="raid.html?artwork=source&amp;mode=pve" data-home-link="raid.html?artwork=source"><span class="home-tool-icon"><svg aria-hidden="true"><use href="#home-map"/></svg></span><span class="home-card-number">02</span><h3>Карты локаций</h3><p>Найди выход, квестовую точку, ключ или нужный контейнер на карте.</p><span class="home-card-link">Изучить локации <svg aria-hidden="true"><use href="#home-arrow"/></svg></span></a>
          <a class="home-tool-card" href="reference.html?tab=items&amp;mode=pve" data-home-link="reference.html?tab=items"><span class="home-tool-icon"><svg aria-hidden="true"><use href="#home-item"/></svg></span><span class="home-card-number">03</span><h3>Предметы</h3><p>Снаряжение, ключи и материалы. Связи с квестами и рецептами.</p><span class="home-card-link">Открыть справочник <svg aria-hidden="true"><use href="#home-arrow"/></svg></span></a>
          <a class="home-tool-card" href="reference.html?tab=ammo&amp;mode=pve" data-home-link="reference.html?tab=ammo"><span class="home-tool-icon"><svg aria-hidden="true"><use href="#home-ammo"/></svg></span><span class="home-card-number">04</span><h3>Боеприпасы</h3><p>Сравни урон и пробитие. Подбери патроны под свой калибр.</p><span class="home-card-link">Выбрать патроны <svg aria-hidden="true"><use href="#home-arrow"/></svg></span></a>
          <a class="home-tool-card" href="boss.html?mode=pve" data-home-link="boss.html"><span class="home-tool-icon"><svg aria-hidden="true"><use href="#home-boss"/></svg></span><span class="home-card-number">05</span><h3>Боссы</h3><p>Здоровье, особенности и места появления в выбранном режиме.</p><span class="home-card-link">Изучить противника <svg aria-hidden="true"><use href="#home-arrow"/></svg></span></a>
          <a class="home-tool-card" href="reference.html?tab=hideout&amp;mode=pve" data-home-link="reference.html?tab=hideout"><span class="home-tool-icon"><svg aria-hidden="true"><use href="#home-hideout"/></svg></span><span class="home-card-number">06</span><h3>Убежище</h3><p>Требования к постройкам, крафты и материалы для улучшений.</p><span class="home-card-link">Подготовить материалы <svg aria-hidden="true"><use href="#home-arrow"/></svg></span></a>
        </div>
  </section>
  <section class="home-trader-section" aria-labelledby="home-trader-title"><div class="home-section-heading"><div><span class="home-kicker">ЗАДАНИЯ ПО ТОРГОВЦАМ</span><h2 id="home-trader-title">У кого берём работу?</h2></div><a href="quests.html?mode=pve" data-home-link="quests.html">Все торговцы <svg aria-hidden="true"><use href="#home-arrow"/></svg></a></div><div class="home-traders" id="home-traders">
      <a class="home-trader" href="quests.html?trader=54cb50c76803fa8b248b4571&amp;mode=pve" data-home-link="quests.html?trader=54cb50c76803fa8b248b4571"><img src="https://assets.tarkov.dev/54cb50c76803fa8b248b4571.webp" alt="" width="58" height="64" loading="lazy"><strong>Прапор</strong><span>Загрузка…</span></a>
      <a class="home-trader" href="quests.html?trader=54cb57776803fa99248b456e&amp;mode=pve" data-home-link="quests.html?trader=54cb57776803fa99248b456e"><img src="https://assets.tarkov.dev/54cb57776803fa99248b456e.webp" alt="" width="58" height="64" loading="lazy"><strong>Терапевт</strong><span>Загрузка…</span></a>
      <a class="home-trader" href="quests.html?trader=579dc571d53a0658a154fbec&amp;mode=pve" data-home-link="quests.html?trader=579dc571d53a0658a154fbec"><img src="https://assets.tarkov.dev/579dc571d53a0658a154fbec.webp" alt="" width="58" height="64" loading="lazy"><strong>Скупщик</strong><span>Загрузка…</span></a>
      <a class="home-trader" href="quests.html?trader=58330581ace78e27b8b10cee&amp;mode=pve" data-home-link="quests.html?trader=58330581ace78e27b8b10cee"><img src="https://assets.tarkov.dev/58330581ace78e27b8b10cee.webp" alt="" width="58" height="64" loading="lazy"><strong>Лыжник</strong><span>Загрузка…</span></a>
      <a class="home-trader" href="quests.html?trader=5935c25fb3acc3127c3d8cd9&amp;mode=pve" data-home-link="quests.html?trader=5935c25fb3acc3127c3d8cd9"><img src="https://assets.tarkov.dev/5935c25fb3acc3127c3d8cd9.webp" alt="" width="58" height="64" loading="lazy"><strong>Миротворец</strong><span>Загрузка…</span></a>
      <a class="home-trader" href="quests.html?trader=5a7c2eca46aef81a7ca2145d&amp;mode=pve" data-home-link="quests.html?trader=5a7c2eca46aef81a7ca2145d"><img src="https://assets.tarkov.dev/5a7c2eca46aef81a7ca2145d.webp" alt="" width="58" height="64" loading="lazy"><strong>Механик</strong><span>Загрузка…</span></a>
      <a class="home-trader" href="quests.html?trader=5ac3b934156ae10c4430e83c&amp;mode=pve" data-home-link="quests.html?trader=5ac3b934156ae10c4430e83c"><img src="https://assets.tarkov.dev/5ac3b934156ae10c4430e83c.webp" alt="" width="58" height="64" loading="lazy"><strong>Барахольщик</strong><span>Загрузка…</span></a>
      <a class="home-trader" href="quests.html?trader=5c0647fdd443bc2504c2d371&amp;mode=pve" data-home-link="quests.html?trader=5c0647fdd443bc2504c2d371"><img src="https://assets.tarkov.dev/5c0647fdd443bc2504c2d371.webp" alt="" width="58" height="64" loading="lazy"><strong>Егерь</strong><span>Загрузка…</span></a>
    </div></section>
  <section class="home-journal-strip" aria-labelledby="home-journal-title"><div class="home-journal-mark"><svg aria-hidden="true"><use href="#home-check"/></svg></div><div class="home-journal-copy"><span class="home-kicker">ЛИЧНЫЙ ЖУРНАЛ</span><h2 id="home-journal-title">Продолжи с того, где остановился.</h2><p id="home-journal-description">Отмечай выполненные задания и возвращайся к своим целям.</p><div class="home-journal-meter"><progress id="home-progress" value="0" max="1" hidden aria-label="Выполненные задания"></progress><span id="home-progress-label">Загружаем журнал…</span></div></div><a class="button" id="home-continue" href="quests.html?mode=pve" data-home-link="quests.html">Открыть журнал <svg aria-hidden="true"><use href="#home-arrow"/></svg></a></section>
  <section class="home-locations" aria-labelledby="home-locations-title"><div class="home-section-heading"><div><span class="home-kicker">ПЛАН НА МЕСТНОСТИ</span><h2 id="home-locations-title">Куда отправляемся?</h2></div><span id="home-locations-total">Загрузка…</span></div><div class="home-location-list" id="home-location-list" aria-live="polite">Загружаем локации…</div></section>
  <section class="home-documents"><div><span class="home-kicker">KORD BREACH</span><h2>Секретные документы</h2><p id="home-documents-summary">Загружаем сводку документов…</p></div><a class="button quiet" href="documents.html">Открыть атлас <svg aria-hidden="true"><use href="#home-doc"/></svg></a></section>
  <footer class="home-footer"><div><img src="assets/site-logo.svg" alt="" width="30" height="30"><span>ESCAPE FROM TARKOV WIKI<small>Неофициальный справочник. Игровые материалы — Battlestate Games.</small></span></div><nav aria-label="Справочные материалы"><a href="https://tarkov.help/ru" target="_blank" rel="noopener noreferrer">Tarkov.help ↗</a><a href="https://escapefromtarkov.fandom.com/ru/wiki/Escape_from_Tarkov_Wiki" target="_blank" rel="noopener noreferrer">Русская Wiki ↗</a></nav></footer>
</main>
  <script type="module" src="bootstrap.mjs?v=documents-exits-20261020"></script>
@endsection
