@foreach($catalogTabs ?? [] as $title => $tabs)
<nav class="bg-white rounded shadow-sm p-3 mb-3" aria-label="{{ $title }}">
    <div class="small text-muted mb-2">{{ $title }}</div>
    <ul class="nav nav-pills flex-wrap gap-2">
        @foreach($tabs as $tab)
        <li class="nav-item"><a class="nav-link {{ $tab['active'] ? 'active' : '' }}" href="{{ $tab['url'] }}" data-turbo="false" @if($tab['active']) aria-current="page" @endif>{{ $tab['label'] }}</a></li>
        @endforeach
    </ul>
</nav>
@endforeach
