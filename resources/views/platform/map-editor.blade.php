@push('head')
<meta name="turbo-visit-control" content="reload">
<link rel="stylesheet" href="/vendor/leaflet.css">
<link rel="stylesheet" href="/admin-map-editor.css?v={{ hash_file('sha256', public_path('admin-map-editor.css')) }}">
@endpush
<section id="map-editor" class="map-editor" data-csrf="{{ csrf_token() }}" data-assets="{{ rtrim(config('app.url'), '/') }}/" data-endpoint="{{ route('platform.map-editor.data', [], false) }}" data-save="{{ route('platform.map-editor.save', [], false) }}" data-items="{{ route('platform.map-editor.items', [], false) }}">
    <div class="me-toolbar">
        <label>Карта<select id="me-map" aria-label="Карта"></select></label>
        <label>Режим<select id="me-mode"><option value="regular">PVP</option><option value="pve">PVE</option></select></label>
        <label>Этаж<select id="me-floor"></select></label>
        <label>Слой точек<select id="me-category"><option value="all">Все категории</option></select></label>
        <label>Поиск<input id="me-search" type="search" maxlength="255" placeholder="Название точки"></label>
    </div>
    <div class="me-actions">
        <button id="me-add" type="button">＋ Поставить точку</button>
        <button id="me-fit" type="button">Показать всю карту</button>
        <button id="me-save" type="button" disabled>Сохранить</button>
        <button id="me-discard" type="button" disabled>Отменить правки</button>
        <button id="me-retry" type="button" hidden>Повторить загрузку</button>
        <label><input id="me-archived" type="checkbox"> Показать архивные</label>
    </div>
    <p id="me-status" role="status" aria-live="polite">Загрузка карт…</p>
    <div id="me-conflict" class="me-conflict" hidden>
        <p>Другой редактор изменил карту. Ваши правки остаются в памяти. Проверьте отличия перед повторным сохранением.</p>
        <pre id="me-differences"></pre>
        <button id="me-rebase" type="button">Применить мои правки к новой версии</button>
        <button id="me-server" type="button">Принять версию сервера</button>
    </div>
    <div class="me-workspace">
        <div id="me-canvas" tabindex="0" aria-label="Карта: выберите точку для перемещения"></div>
        <aside class="me-inspector">
            <p id="me-help">Выберите точку на карте или в списке. Для новой точки нажмите «Поставить точку», затем нужное место. Изменения публикуются только после сохранения.</p>
            <div id="me-fields" hidden>
                <label>Название<input id="me-name" maxlength="255" required aria-describedby="me-name-error"><span id="me-name-error" class="me-error"></span></label>
                <label>Описание<textarea id="me-description" rows="3" maxlength="10000" aria-describedby="me-description-error"></textarea><span id="me-description-error" class="me-error"></span></label>
                <label>Категория<select id="me-type" aria-describedby="me-type-error"></select><span id="me-type-error" class="me-error"></span></label>
                <label id="me-loot-label">Контейнер<select id="me-loot" aria-describedby="me-loot-error"></select><span id="me-loot-error" class="me-error"></span></label>
                <div id="me-item-fields" hidden><label>Поиск предмета<input id="me-item-search" type="search" maxlength="255"></label><button id="me-item-find" type="button">Найти предмет</button><label>Предмет<select id="me-itemId" aria-describedby="me-itemId-error"></select><span id="me-itemId-error" class="me-error"></span></label></div>
                <label>Этаж точки<select id="me-point-floor" aria-describedby="me-point-floor-error"></select><span id="me-point-floor-error" class="me-error"></span></label>
                <div class="me-coordinates">
                    <label>X<input id="me-x" type="number" step="any" min="-10000" max="10000" aria-describedby="me-position-error"></label>
                    <label>Высота, м<input id="me-y" type="number" step="any" min="-10000" max="10000" aria-describedby="me-position-error"></label>
                    <label>Z<input id="me-z" type="number" step="any" min="-10000" max="10000" aria-describedby="me-position-error"></label>
                </div>
                <span id="me-position-error" class="me-error"></span>
                <p>Перетащите метку. С клавиатуры: выберите метку и нажимайте стрелки (Shift — шаг 10 м). Координаты можно ввести вручную.</p>
                <button id="me-place" type="button">Переместить следующим нажатием</button>
                <button id="me-archive" type="button">В архив</button>
            </div>
            <p id="me-count"></p>
            <div id="me-list" class="me-list" aria-label="Точки выбранного слоя"></div>
            <button id="me-more" type="button" hidden>Показать ещё</button>
        </aside>
    </div>
</section>
<script src="/vendor/leaflet.js"></script>
<script type="importmap">{!! json_encode(['imports' => collect(['raid-model.mjs', 'raid-map-geometry.mjs'])->mapWithKeys(fn ($file) => ['/'.$file => '/'.$file.'?v='.hash_file('sha256', public_path($file))])->all()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
<script type="module" src="/admin-map-editor.mjs?v={{ hash_file('sha256', public_path('admin-map-editor.mjs')) }}"></script>
