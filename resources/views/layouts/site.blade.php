<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#101412">
  <meta name="wiki-runtime" content="{{ $runtime }}">
  <meta name="description" content="{{ $meta['description'] }}">
@if ($page === 'account')
  <meta name="referrer" content="no-referrer">
  <meta name="robots" content="noindex, nofollow">
@endif
  <base href="/">
  <title>{{ $title }}</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="stylesheet" href="game-clock.css?v=important-items-20261017">
  <script type="module" src="game-clock.mjs?v=important-items-20261017"></script>
  <link rel="stylesheet" href="legal.css?v=mobile-legal-20261017">
@foreach ($meta['styles'] as $style)
@if ($style === 'reference-ui.css')
  <link rel="stylesheet" href="{{ $style }}?v=world-media-20261017">
@elseif ($style === 'account-responsive.css')
  <link rel="stylesheet" href="{{ $style }}?v=tablet-account-20261017">
@else
  <link rel="stylesheet" href="{{ $style }}?v={{ $style === 'account.css' ? 'profile-details-20261017' : ($style === 'documents.css' ? 'documents-access-20261020' : ($style === 'raid-responsive.css' ? 'tablet-20261017b' : ($style === 'reference-responsive.css' ? 'mobile-btr-20261017' : ($style === 'quests-responsive.css' ? 'quest-filters-20261017' : 'release-20261016')))) }}">
@endif
@endforeach
  <link rel="stylesheet" href="site-responsive.css?v=desktop-20261017">
@foreach (app(\App\Services\PageModules::class)->preloads($page) as $module)
  <link rel="modulepreload" href="{{ $module }}" @if ($module === ($meta['module'] ?? null)) data-page-module @endif>
@endforeach
  <meta name="wiki-routing" content="pages">
@if ($params !== [])
  <script type="application/json" id="wiki-page-params">{!! json_encode($params, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
@endif
@if (in_array($page, ['index','documents','raid','quests','quest','reference','boss','account'], true))
<style>
body[data-content-state="loading"] > :is(main,#app-layout,#raid-layout,.site-legal-footer),
body[data-content-state="error"] > :is(main,#app-layout,#raid-layout,.site-legal-footer) { visibility: hidden; }
.page-boot-status { position: fixed; inset: var(--header-height, 100px) 16px 16px; display: grid; place-content: center; gap: 16px; text-align: center; color: #c6d1c1; z-index: 4; }
.reference-detail-layout { display: flex; flex-direction: column; }
.reference-detail-layout > .reference-mode { align-self: flex-end; max-width: 180px; }
.reference-detail-layout > .reference-main { flex: 1; min-height: 0; width: 100%; }
body[data-content-state="ready"] > .page-boot-status { display: none; }
body[data-content-state="loading"] .topbar .data-status { visibility: hidden; }
</style>
@endif
</head>
<body {!! $meta['body_attributes'] !!} @if (in_array($page, ['index','documents','raid','quests','quest','reference','boss','account'], true)) data-content-state="loading" @endif>
@if (in_array($page, ['index','documents','raid','quests','quest','reference','boss','account'], true))
<div class="page-boot-status" id="page-boot-status" role="status" aria-live="polite">Загрузка страницы…</div>
<noscript>Для отображения данных включите JavaScript.</noscript>
@endif
@yield('content')
@unless (in_array($page, ['documents', 'raid'], true))
<footer class="site-legal-footer">@include('components.legal-navigation')</footer>
@endunless
</body>
</html>
