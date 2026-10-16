@extends('layouts.site')
@section('content')
<header class="qmap-tools"><strong id="qmap-title">Карта задания</strong><div><button id="qmap-points" type="button">К меткам</button><button id="qmap-fit" type="button">Вся карта</button><button id="qmap-full" type="button" aria-label="Карта на весь экран">⛶</button></div></header>
<main id="qmap" role="region" aria-label="Карта мест выполнения. Перемещение стрелками, масштаб кнопками плюс и минус." tabindex="0"></main>
<div id="qmap-loading" role="status">Загрузка схемы…</div><footer><span id="qmap-count"></span><a href="https://escapefromtarkov.fandom.com/ru/wiki/Escape_from_Tarkov_Wiki" target="_blank" rel="noopener noreferrer">Русская Wiki ↗</a></footer>
<script src="vendor/leaflet.js"></script><script type="module" src="quest-map-source.mjs?v=quest-guides-20261016"></script>
@endsection
