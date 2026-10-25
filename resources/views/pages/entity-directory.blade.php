@extends('layouts.site')
@section('content')
@include('headers.directory')
<main class="seo-directory">
@include('components.entity-seo')
@php($interactiveUrl = (parse_url($seo['canonical'], PHP_URL_PATH) ?: '/'.$page).(($params['mode'] ?? 'pve') === 'regular' ? '?mode=regular' : ''))
<p><a class="button" href="{{ $interactiveUrl }}">Открыть интерактивный раздел</a></p>
</main>
@endsection
