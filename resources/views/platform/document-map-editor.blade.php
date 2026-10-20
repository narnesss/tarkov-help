@push('head')
<meta name="turbo-visit-control" content="reload">
<link rel="stylesheet" href="{{ asset('admin-document-map.css') }}?v={{ substr(hash_file('sha256', public_path('admin-document-map.css')),0,12) }}">
@endpush
<section id="document-map-editor" class="dme" aria-label="Визуальный редактор карт документов"
 data-read="{{ route('platform.document-maps.data') }}" data-save="{{ route('platform.document-maps.save') }}"
 data-artwork="{{ route('platform.document-maps.artwork') }}">
 <input type="hidden" id="dme-token" value="{{ csrf_token() }}">
 <p>Выберите точку и перетащите значок или нажмите «Указать место» и затем на схему. Изменения публикуются после нажатия «Сохранить положение». Выходы здесь перемещаются независимо от интерактивных карт.</p>
 <div class="dme-toolbar">
  <label for="dme-map">Карта<select id="dme-map">@foreach($editorMaps as $map)<option value="{{ $map['id'] }}">{{ $map['name'] }}</option>@endforeach</select></label>
  <label for="dme-mode">Режим выходов<select id="dme-mode"><option value="pve">PvE</option><option value="regular">PvP</option></select></label>
  <label for="dme-floor">Показать этаж<select id="dme-floor"></select></label>
  <button type="button" id="dme-reload">Обновить карту</button>
 </div>
 <p id="dme-status" role="status" aria-live="polite">Загрузка карты…</p>
 <p id="dme-error" class="dme-error" role="alert" hidden></p>
 <button type="button" id="dme-rebase" hidden>Загрузить свежие данные, сохранив мою правку</button>
 <div class="dme-layout">
  <aside class="dme-panel" aria-label="Точки и редактирование">
   <label for="dme-search">Поиск точек<input id="dme-search" type="search" maxlength="255" placeholder="Название или описание"></label>
   <label for="dme-kind-filter">Тип точек<select id="dme-kind-filter"><option value="all">Документы и выходы</option><option value="document">Документы</option><option value="exit">Выходы ЧВК</option></select></label>
   <div class="dme-add"><button type="button" id="dme-add-document">Добавить документ</button><button type="button" id="dme-add-exit">Добавить выход ЧВК</button></div>
   <div id="dme-points" class="dme-points" role="group" aria-label="Список точек"></div>
   <section id="dme-form" aria-label="Положение выбранной точки" hidden>
    <h2 id="dme-selected"></h2>
    <div id="dme-new-fields" hidden>
     <label for="dme-title">Название новой точки<input id="dme-title" maxlength="255" aria-describedby="dme-title-error"></label>
     <p id="dme-title-error" class="dme-error" hidden></p>
     <label id="dme-document-label" for="dme-document">Документ<select id="dme-document" aria-describedby="dme-documentId-error"></select></label>
     <p id="dme-documentId-error" class="dme-error" hidden></p>
    </div>
    <label for="dme-point-floor">Этаж точки<select id="dme-point-floor" aria-describedby="dme-floor-error"></select></label>
    <p id="dme-floor-error" class="dme-error" hidden></p>
    <div class="dme-coordinates">
     <label for="dme-x">X, % этажа<input id="dme-x" type="number" min="0" max="100" step="0.01" inputmode="decimal" aria-describedby="dme-x-error"></label>
     <label for="dme-y">Y, % этажа<input id="dme-y" type="number" min="0" max="100" step="0.01" inputmode="decimal" aria-describedby="dme-y-error"></label>
    </div>
    <p id="dme-x-error" class="dme-error" hidden></p><p id="dme-y-error" class="dme-error" hidden></p>
    <p>Координаты считаются от выбранного этажа. Для небольшого сдвига выделенного значка используйте стрелки клавиатуры; Shift — шаг 0,1%.</p>
    <div class="dme-actions"><button type="button" id="dme-place">Указать место на схеме</button><button type="button" id="dme-save">Сохранить положение</button><button type="button" id="dme-cancel">Отменить правку</button></div>
    <a id="dme-details" target="_blank" rel="noopener" hidden>Открыть подробную карточку</a>
   </section>
  </aside>
  <div class="dme-canvas-panel">
   <div class="dme-zoom" role="group" aria-label="Масштаб схемы"><button type="button" id="dme-zoom-out" aria-label="Уменьшить схему">−</button><output id="dme-zoom">100%</output><button type="button" id="dme-zoom-in" aria-label="Увеличить схему">+</button></div>
   <div id="dme-stage" class="dme-stage" tabindex="0" aria-label="Схема карты. Прокрутка для перемещения; кнопки плюс и минус меняют масштаб.">
    <div id="dme-plane" class="dme-plane"><img id="dme-image" alt="Схема выбранного этажа" draggable="false"><div id="dme-markers"></div></div>
   </div>
   <p id="dme-hint">На телефоне и планшете увеличьте схему кнопкой «+». Для точной установки можно выбрать точку из списка.</p>
  </div>
 </div>
</section>
<script type="module" src="{{ asset('admin-document-map.mjs') }}?v={{ substr(hash_file('sha256', public_path('admin-document-map.mjs')),0,12) }}"></script>
