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
@foreach ($meta['styles'] as $style)
  <link rel="stylesheet" href="{{ $style }}?v={{ $style === 'account.css' ? 'email-3' : 'account-1' }}">
@endforeach
  <link rel="modulepreload" href="profile-store.mjs">
  <meta name="wiki-routing" content="pages">
@if ($params !== [])
  <script type="application/json" id="wiki-page-params">{!! json_encode($params, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
@endif
</head>
<body {!! $meta['body_attributes'] !!}>
@yield('content')
</body>
</html>
