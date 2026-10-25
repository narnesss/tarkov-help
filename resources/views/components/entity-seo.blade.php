@php
    $entity = $serverContent['entity'];
    $safeEntityUrl = static fn ($url): bool => is_string($url)
        && !preg_match('/[\x00-\x20<>\\\\]/u', $url)
        && ((str_starts_with($url, '/') && !str_starts_with($url, '//'))
            || (filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)));
@endphp
<section class="seo-reading seo-entity" data-seo-fallback aria-labelledby="entity-seo-title">
@if ($seo['breadcrumbs'])
<nav class="quest-breadcrumbs" aria-label="Путь к странице">
@foreach ($seo['breadcrumbs'] as $crumb)<a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>@if (!$loop->last)<span aria-hidden="true">›</span>@endif @endforeach
</nav>
@endif
<h1 id="entity-seo-title">{{ $entity['name'] }}</h1>
@php($entityPath = parse_url($seo['canonical'], PHP_URL_PATH))
<nav class="seo-modes" aria-label="Режим игры">
<a class="button quiet" href="{{ $entityPath }}" @if (($params['mode'] ?? 'pve') === 'pve') aria-current="page" @endif>PVE</a>
<a class="button quiet" href="{{ $entityPath }}?mode=regular" @if (($params['mode'] ?? 'pve') === 'regular') aria-current="page" @endif>PVP</a>
</nav>
@if ($entity['description'] ?? '')<p>{{ $entity['description'] }}</p>@endif
@if ($entity['facts'] ?? [])<ul>@foreach ($entity['facts'] as $fact)<li>{{ $fact }}</li>@endforeach</ul>@endif
@if ($entity['links'] ?? [])
<nav aria-label="Связанные страницы"><ul class="seo-entity-links">
@foreach ($entity['links'] as $link)<li>@if ($safeEntityUrl($link['url'] ?? null))<a href="{{ $link['url'] }}">{{ $link['name'] }}</a>@else{{ $link['name'] }}@endif</li>@endforeach
</ul></nav>
@endif
@if (($serverContent['pages'] ?? 1) > 1)
<nav class="seo-pagination" aria-label="Страницы каталога">
@if ($safeEntityUrl($serverContent['previousUrl'] ?? null))<a href="{{ $serverContent['previousUrl'] }}">Предыдущая страница</a>@endif
<span>Страница {{ $serverContent['number'] }} из {{ $serverContent['pages'] }}</span>
@if ($safeEntityUrl($serverContent['nextUrl'] ?? null))<a href="{{ $serverContent['nextUrl'] }}">Следующая страница</a>@endif
</nav>
@endif
</section>
