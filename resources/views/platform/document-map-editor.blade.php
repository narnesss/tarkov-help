@push('head')
<meta name="turbo-visit-control" content="reload">
<link rel="stylesheet" href="/vendor/leaflet.css">
<link rel="stylesheet" href="/admin-map-editor.css?v={{ hash_file('sha256', public_path('admin-map-editor.css')) }}">
<link rel="stylesheet" href="/admin-document-map.css?v={{ hash_file('sha256', public_path('admin-document-map.css')) }}">
@endpush
<section id="document-map-editor" class="map-editor document-map-editor" aria-label="Визуальный редактор карт документов"
 data-csrf="{{ csrf_token() }}" data-assets="{{ rtrim(config('app.url'), '/') }}/"
 data-read="{{ route('platform.document-maps.data', [], false) }}" data-save="{{ route('platform.document-maps.save', [], false) }}"
 data-batch="{{ route('platform.document-maps.batch', [], false) }}" data-remove="{{ route('platform.document-maps.remove', [], false) }}"
 data-artwork="{{ route('platform.document-maps.artwork', [], false) }}">
    @include('platform.partials.map-editor-controls', ['prefix'=>'dme','maps'=>$editorMaps,'defaultMode'=>'pve','categories'=>['document'=>'Документы','exit'=>'Выходы ЧВК']])
    <div class="me-workspace">
        <div id="dme-canvas" class="me-canvas" tabindex="0" aria-label="Карта: выберите точку для перемещения"></div>
        <aside class="me-inspector">
            <p id="dme-help">Выберите точку на карте или в списке. Для новой точки нажмите «Поставить точку», затем нужное место. Изменения публикуются только после сохранения.</p>
            <div id="dme-fields" class="me-fields" hidden>
                <label>Название<input id="dme-name" maxlength="255" required aria-describedby="dme-title-error"><span id="dme-title-error" class="me-error"></span></label>
                <label>Описание<textarea id="dme-description" rows="3" maxlength="10000" aria-describedby="dme-description-error"></textarea><span id="dme-description-error" class="me-error"></span></label>
                <label>Категория<select id="dme-type" aria-describedby="dme-kind-error"><option value="document">Документ</option><option value="exit">Выход ЧВК</option></select><span id="dme-kind-error" class="me-error"></span></label>
                <label id="dme-document-label">Документ<select id="dme-document" aria-describedby="dme-documentId-error"></select><span id="dme-documentId-error" class="me-error"></span></label>
                <label>Этаж точки<select id="dme-point-floor" aria-describedby="dme-floor-error"></select><span id="dme-floor-error" class="me-error"></span></label>
                <div class="me-coordinates">
                    <label>X, % этажа<input id="dme-x" type="number" min="0" max="100" step="any" inputmode="decimal" aria-describedby="dme-x-error"><span id="dme-x-error" class="me-error"></span></label>
                    <label>Y, % этажа<input id="dme-y" type="number" min="0" max="100" step="any" inputmode="decimal" aria-describedby="dme-y-error"><span id="dme-y-error" class="me-error"></span></label>
                </div>
                <p>Перетащите метку. С клавиатуры: выберите метку и нажимайте стрелки (Shift — шаг 0,1%). Координаты можно ввести вручную.</p>
                <button id="dme-place" type="button">Переместить следующим нажатием</button>
                <button id="dme-discard-point" type="button" disabled>Отменить правки выбранной точки</button>
                <button id="dme-archive" type="button">В архив</button>
                <a id="dme-details" target="_blank" rel="noopener" hidden>Открыть подробную карточку</a>
            </div>
            <p id="dme-count"></p>
            <div id="dme-list" class="me-list" aria-label="Точки выбранного слоя"></div>
            <button id="dme-more" type="button" hidden>Показать ещё</button>
        </aside>
    </div>
</section>
<script src="/vendor/leaflet.js"></script>
<script type="importmap">{!! json_encode(['imports' => collect(['document-map.mjs','admin-map-ui.mjs','admin-map-drafts.mjs','admin-document-drafts.mjs'])->mapWithKeys(fn ($file) => ['/'.$file => '/'.$file.'?v='.hash_file('sha256', public_path($file))])->all()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
<script type="module" src="/admin-document-map.mjs?v={{ hash_file('sha256', public_path('admin-document-map.mjs')) }}"></script>
