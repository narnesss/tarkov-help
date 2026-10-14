@extends('layouts.site')
@section('content')
<header class="topbar">@include('components.brand')<nav class="atlas-modes" aria-label="Раздел сайта"><a href="/">Главная</a><a href="/raid">Карты</a><a href="/quests">Квесты</a><a href="/reference">Справочник</a></nav></header>
<main style="max-width:720px;margin:10vh auto;padding:24px"><section class="guide-section"><span class="eyebrow">404</span><h1>Страница не найдена</h1><p>Проверьте адрес или найдите нужный квест, карту или предмет через поиск на главной.</p><a class="button" href="/">На главную</a></section></main>
@endsection
