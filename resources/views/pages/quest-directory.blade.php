@extends('layouts.site')
@section('content')
@include('headers.directory')
<main class="quest-layout">
<h1>{{ $serverContent['title'] ?? 'Квесты Таркова' }} — страница {{ $serverContent['number'] }}</h1>
@php($directoryPath = parse_url($seo['canonical'], PHP_URL_PATH))
<nav class="seo-modes" aria-label="Игровой режим">
<a class="button" href="{{ $directoryPath }}" @if (($params['mode'] ?? 'pve') === 'pve') aria-current="page" @endif>PVE</a>
<a class="button" href="{{ $directoryPath }}?mode=regular" @if (($params['mode'] ?? 'pve') === 'regular') aria-current="page" @endif>PVP</a>
</nav>
@include('components.quest-seo')
<p><a class="button" href="/quests{{ ($params['mode'] ?? 'pve') === 'regular' ? '?mode=regular' : '' }}">Открыть поиск и фильтры квестов</a></p>
</main>
@endsection
