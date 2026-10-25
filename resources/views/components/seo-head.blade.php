  <meta name="description" content="{{ $seo['description'] }}">
  <meta name="robots" content="{{ $seo['robots'] }}">
@if ($seo['canonical'])
  <link rel="canonical" href="{{ $seo['canonical'] }}">
  <meta property="og:url" content="{{ $seo['canonical'] }}">
@endif
  <meta property="og:type" content="website">
  <meta property="og:locale" content="ru_RU">
  <meta property="og:site_name" content="Tarkov Compass">
  <meta property="og:title" content="{{ $seo['title'] }}">
  <meta property="og:description" content="{{ $seo['description'] }}">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="{{ $seo['title'] }}">
  <meta name="twitter:description" content="{{ $seo['description'] }}">
@if ($seo['schema'] !== [])
  <script type="application/ld+json">{!! json_encode($seo['schema'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
@endif
