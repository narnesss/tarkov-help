@extends('layouts.site')
@section('content')
@include('headers.' . $page)

  <div class="raid-layout" id="raid-layout">
    <button class="raid-backdrop" id="raid-backdrop" aria-label="Закрыть фильтры" hidden></button>
    <aside class="raid-sidebar" aria-label="Локация и слои">
      <div class="raid-sidebar-top">
        <label class="raid-mode-label">Режим<select id="raid-mode"><option value="pve" selected>PVE</option><option value="regular">PVP</option></select></label><label class="eyebrow" for="raid-map-select">ИНТЕРАКТИВНЫЕ КАРТЫ <span>01—13</span></label>
        <select id="raid-map-select" aria-label="Локация"><option>Загрузка…</option></select>
        <label class="raid-artwork-label" for="raid-artwork">Схема<select id="raid-artwork" aria-label="Подложка карты"><option value="source">Основная</option><option value="documents">Альтернативная</option></select></label>
        <input type="search" id="raid-search" placeholder="Сейф, тайник, выход, квест…" aria-label="Поиск объектов" autocomplete="off">
        <div class="raid-tabs" role="tablist"><button id="raid-filters-tab" role="tab" aria-selected="true" aria-controls="raid-filters">Слои</button><button id="raid-list-tab" role="tab" aria-selected="false" aria-controls="raid-results">Объекты <span id="raid-list-count">0</span></button></div>
      </div>
      <div class="raid-scroll">
        <section id="raid-filters" role="tabpanel" aria-labelledby="raid-filters-tab">
          <div class="raid-filter-actions"><button id="raid-show-all">Показать всё</button><button id="raid-hide-all">Скрыть всё</button></div>
          <div id="raid-quest-filter" class="raid-quest-filter" hidden><span id="raid-quest-name"></span><a id="raid-quest-link" href="quests.html">Открыть квест</a><button class="text-button" id="raid-quest-clear">Показать все объекты</button></div><div id="raid-categories"></div>
          <details hidden id="raid-loose-details" class="loot-details raid-loose-details"><summary>Выбрать предметы <span id="loose-type-count"></span></summary><input id="loose-item-search" type="search" placeholder="Видеокарта, ключ, стимулятор…" aria-label="Поиск предметов вне контейнеров" autocomplete="off"><div class="loose-item-actions"><button id="loose-select-all" type="button">Выбрать все</button><button id="loose-select-none" type="button">Снять все</button></div><p id="loose-item-selection" class="loose-item-selection" role="status"></p><div id="loose-item-options" class="loose-item-options"></div></details>
          <details class="loot-details"><summary>Типы контейнеров <span id="loot-type-count"></span></summary><div id="raid-loot-types"></div></details>
          <div class="raid-filter-extra"><label class="raid-size-label" for="raid-icon-size">Размер значков <output id="raid-icon-value">24</output> px<input id="raid-icon-size" type="range" min="16" max="36" step="2" value="24"></label><label>Личные отметки<select id="raid-status"><option value="all">Все объекты</option><option value="remaining">Не отмеченные</option><option value="checked">Отмеченные</option></select></label><label class="raid-check"><input type="checkbox" id="raid-labels" checked> Подписи ориентиров</label><label class="raid-check"><input type="checkbox" id="raid-legacy"> Старые неподтверждённые точки</label><a class="guide-link" href="reference.html?tab=btr">Маршруты БТР ↗</a><button class="button" id="raid-reset">Сбросить фильтры</button></div>
        </section>
        <section id="raid-results" role="tabpanel" aria-labelledby="raid-list-tab" hidden><p class="raid-list-hint">Выберите объект, чтобы показать его на карте.</p><div id="raid-list"></div><button class="button" id="raid-more" hidden>Показать ещё</button></section>
      </div>
      <div class="map-legal-links">@include('components.legal-navigation')</div>
      </aside>
    <main class="raid-main">
      <div class="raid-heading"><div><div class="eyebrow">ЛУТ / СПАВНЫ / ВЫХОДЫ</div><h1 id="raid-title">Интерактивные карты</h1></div><div class="raid-floor-controls"><label for="raid-floor">Этаж</label><select id="raid-floor"></select><button id="raid-fit" class="button" title="Показать всю карту">Вся карта</button><button id="raid-fullscreen" class="icon-button" aria-label="На весь экран">⛶</button></div></div>
      <div class="raid-canvas-wrap"><div id="raid-map" role="region" aria-label="Интерактивная карта. Перемещение стрелками, масштаб плюс и минус." tabindex="0"></div><div id="raid-loading" class="raid-loading" role="status">Загрузка карты…</div><div class="raid-map-status"><span id="raid-visible">0 объектов</span><span id="raid-floor-note"></span></div>
        <aside id="raid-detail" class="raid-detail" aria-label="Выбранный объект" hidden><div class="raid-detail-heading"><span id="poi-category" class="eyebrow"></span><button id="poi-close" class="icon-button" aria-label="Закрыть объект">×</button></div><h2 id="poi-title"></h2><span id="poi-floor" class="poi-floor"></span><div id="poi-info"></div><button id="poi-checked" class="button primary">Отметить посещённым</button><div class="detail-actions"><button id="poi-center" class="button">На карте</button><button id="poi-copy" class="button">Ссылка</button></div><label class="note-label" for="poi-note">Личная заметка</label><textarea id="poi-note" rows="2" maxlength="2000" placeholder="Заметка об этом месте…"></textarea><div id="poi-overlap"></div></aside>
      </div>
      <footer class="raid-footer"><span id="raid-map-credit">Интерактивная карта</span><span id="raid-map-status"></span></footer>
    </main>
  </div>
  <dialog id="raid-about-dialog" class="dialog raid-about-dialog"><div class="dialog-heading"><h2>Интерактивные карты</h2><button id="raid-about-close" class="icon-button" aria-label="Закрыть">×</button></div><p>Выберите локацию, этаж и нужные слои. Отметки и заметки сохраняются в вашем профиле.</p><ul class="source-list"><li><a href="https://tarkov.help/ru" target="_blank" rel="noopener noreferrer">Tarkov.help — прохождения ↗</a></li><li><a href="https://escapefromtarkov.fandom.com/ru/wiki/Escape_from_Tarkov_Wiki" target="_blank" rel="noopener noreferrer">Русская Wiki ↗</a></li></ul><p class="muted">Авторы карт: Shebuka, RE3MR, Jindouz, xTycho, NepPure и участники сообщества. Игровые материалы — Battlestate Games. Карты распространяются на условиях CC BY-NC-SA 4.0; исходные права сохранены.</p></dialog>
  <div class="toast" id="raid-toast" role="status" hidden></div>
  <script src="vendor/leaflet.js"></script>
  <script type="module" src="bootstrap.mjs?v=tablet-20261017"></script>
@endsection
