<!doctype html>
<html lang="ru">
<head>
@php
  $serverDirectory = (int) ($serverContent['number'] ?? $params['page'] ?? 1) > 1 && isset($serverContent['pages']);
  $interactiveBoot = !$serverDirectory && isset($meta['module']);
@endphp
  <script>
    document.documentElement.classList.add('js-enabled');
    document.addEventListener('DOMContentLoaded', function () {
      if (document.body.dataset.pageBoot === 'document') document.body.dataset.contentState = 'ready';
    });
  </script>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#101412">
  <meta name="wiki-runtime" content="{{ $runtime }}">
<!-- SEO:START -->
@include('components.seo-head')
<!-- SEO:END -->
@if ($page === 'account')
  <meta name="referrer" content="no-referrer">
@endif
  <base href="/">
  <title>{{ $title }}</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
@yield('import-map')  <link rel="stylesheet" href="game-clock.css?v=important-items-20261017">
  <script type="module" src="game-clock.mjs?v=important-items-20261017"></script>
  <link rel="stylesheet" href="legal.css?v=mobile-legal-20261017">
@foreach ($meta['styles'] as $style)
@if ($style === 'reference-ui.css')
  <link rel="stylesheet" href="{{ $style }}?v=world-media-20261017">
@elseif ($style === 'account-responsive.css')
  <link rel="stylesheet" href="{{ $style }}?v=tablet-account-20261017">
@elseif ($style === 'guide.css')
  <link rel="stylesheet" href="guide.css?v=02caea5331ad799e">
@else
  <link rel="stylesheet" href="{{ $style }}?v={{ $style === 'account.css' ? 'profile-details-20261017' : ($style === 'documents.css' ? 'documents-exits-20261020' : ($style === 'raid-responsive.css' ? 'tablet-20261017b' : ($style === 'reference-responsive.css' ? 'mobile-btr-20261017' : ($style === 'quests-responsive.css' ? 'quest-filters-20261017' : 'release-20261016')))) }}">
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
<style>
.js-enabled body[data-content-state="loading"] > :where(main,#app-layout,#raid-layout,.site-legal-footer,[data-seo-fallback]),
.js-enabled body[data-content-state="error"] > :where(main,#app-layout,#raid-layout,.site-legal-footer) { visibility: hidden; }
.page-boot-status { display: none; }
.js-enabled .page-boot-status { position: fixed; inset: var(--header-height, 100px) 16px 16px; display: grid; justify-items: center; place-content: center; gap: 16px; text-align: center; color: #c6d1c1; z-index: 4; }
.page-boot-spinner { width: 44px; height: 44px; box-sizing: border-box; border: 3px solid #374336; border-top-color: #d4c58e; border-radius: 50%; animation: page-boot-spin .85s linear infinite; }
@keyframes page-boot-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .page-boot-spinner { animation: none; } }
.reference-detail-layout { display: flex; flex-direction: column; }
.reference-detail-layout > .reference-mode { align-self: flex-end; max-width: 180px; }
.reference-detail-layout > .reference-main { flex: 1; min-height: 0; width: 100%; }
.js-enabled body[data-content-state="ready"] > .page-boot-status { display: none; }
body[data-content-state="loading"] .topbar .data-status { visibility: hidden; }
.js-enabled body[data-server-content][data-content-state="error"] > :is(main,[data-seo-fallback]) { visibility: visible; }
.js-enabled body[data-server-content][data-content-state="error"] > .page-boot-status { position: relative; inset: auto; padding: 16px; }
.js-enabled body[data-page-boot="interactive"]:not([data-content-state="error"]) .seo-reading { display: none; }
.seo-reading { padding: 20px; max-width: 100%; overflow-wrap: anywhere; }
.seo-reading li { margin-block: 8px; }
.seo-reading a { display: inline-flex; align-items: center; min-height: 44px; }
.seo-quest-list { columns: 2 260px; padding-inline-start: 22px; }
.seo-entity { width: min(100%, 1200px); margin-inline: auto; }
.seo-entity h1 { margin-block: 12px; font-size: clamp(24px, 4vw, 38px); line-height: 1.2; }
.seo-entity-links { columns: 2 260px; padding-inline-start: 22px; }
.seo-pagination { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 24px; }
.seo-modes { display: flex; flex-wrap: wrap; gap: 8px; }
.seo-modes a { min-width: 44px; justify-content: center; }
.seo-modes [aria-current="page"] { font-weight: 700; text-decoration: underline; }
.seo-directory { width: min(100%, 1200px); margin-inline: auto; padding: 20px; }
.seo-directory > .seo-entity { padding: 0; }
body[data-server-entity][data-content-state="error"], html:not(.js-enabled) body[data-server-entity] { height: auto; min-height: 100dvh; overflow: auto; }
body[data-server-entity][data-content-state="error"] > main:not(.seo-directory), html:not(.js-enabled) body[data-server-entity] > main:not(.seo-directory) { display: none; }
body[data-server-entity][data-content-state="error"] > :is(#app-layout,#raid-layout), html:not(.js-enabled) body[data-server-entity] > :is(#app-layout,#raid-layout) { display: none; }
</style>
</head>
<body {!! $meta['body_attributes'] !!} @if ($serverContent ?? []) data-server-content="true" @endif @if (isset($serverContent['entity'])) data-server-entity="true" @endif data-page-boot="{{ $interactiveBoot ? 'interactive' : 'document' }}" data-content-state="loading">
<div class="page-boot-status" id="page-boot-status" role="status" aria-live="polite"><span class="page-boot-spinner" aria-hidden="true"></span><span>Загрузка страницы…</span></div>
@if ($interactiveBoot && !($serverContent ?? []))<noscript>Для отображения интерактивных данных включите JavaScript.</noscript>@endif
@yield('content')
@unless (in_array($page, ['documents', 'raid'], true))
<footer class="site-legal-footer">@include('components.legal-navigation')</footer>
@endunless
</body>
</html>
