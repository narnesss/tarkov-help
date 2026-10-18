@extends('layouts.site')
@section('content')
<header class="qmap-tools"><strong id="qmap-title">Карта задания</strong><div><button id="qmap-points" type="button">К меткам</button><button id="qmap-fit" type="button">Вся карта</button><button id="qmap-full" type="button" aria-label="Карта на весь экран">⛶</button></div></header>
<main id="qmap" role="region" aria-label="Карта мест выполнения. Перемещение стрелками, масштаб кнопками плюс и минус." tabindex="0"></main>
<div id="qmap-loading" role="status">Загрузка схемы…</div><footer><span id="qmap-count"></span><a id="qmap-source" href="https://escapefromtarkov.fandom.com/ru/wiki/Escape_from_Tarkov_Wiki" target="_blank" rel="noopener noreferrer">Источник карты ↗</a><a href="licenses/QUEST-GUIDES.txt" target="_blank" rel="noopener">Авторы и лицензии</a></footer>
<script src="vendor/leaflet.js"></script><script type="module" src="quest-map-source.mjs?v=loading-20261018"></script>
@endsection
