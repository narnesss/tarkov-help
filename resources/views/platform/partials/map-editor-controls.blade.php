<div class="me-toolbar">
    <label>Карта<select id="{{ $prefix }}-map" aria-label="Карта">@foreach($maps ?? [] as $map)<option value="{{ $map['id'] }}">{{ $map['name'] ?? $map['displayName'] }}</option>@endforeach</select></label>
    <label>Режим<select id="{{ $prefix }}-mode"><option value="regular">PVP</option><option value="pve" @selected(($defaultMode ?? 'regular') === 'pve')>PVE</option></select></label>
    <label>Этаж<select id="{{ $prefix }}-floor"></select></label>
    <label>Слой точек<select id="{{ $prefix }}-category"><option value="all">Все категории</option>@foreach($categories ?? [] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
    <label>Поиск<input id="{{ $prefix }}-search" type="search" maxlength="255" placeholder="Название точки"></label>
</div>
<div class="me-actions">
    <button id="{{ $prefix }}-add" type="button">＋ Поставить точку</button>
    <button id="{{ $prefix }}-fit" type="button">Показать всю карту</button>
    <button id="{{ $prefix }}-save" class="me-save" type="button" disabled>Сохранить</button>
    <button id="{{ $prefix }}-discard" type="button" disabled>Отменить правки</button>
    <button id="{{ $prefix }}-retry" type="button" hidden>Повторить загрузку</button>
    <label><input id="{{ $prefix }}-archived" type="checkbox"> Показать архивные</label>
</div>
<p id="{{ $prefix }}-status" class="me-status" role="status" aria-live="polite">Загрузка карт…</p>
<div id="{{ $prefix }}-conflict" class="me-conflict" hidden>
    <p>Другой редактор изменил карту. Ваши правки остаются в памяти. Проверьте отличия перед повторным сохранением.</p>
    <pre id="{{ $prefix }}-differences"></pre>
    <button id="{{ $prefix }}-rebase" type="button">Применить мои правки к новой версии</button>
    <button id="{{ $prefix }}-server" type="button">Принять версию сервера</button>
</div>
