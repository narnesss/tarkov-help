<header class="topbar">
  @include('components.brand')
  <nav class="atlas-modes" aria-label="Раздел сайта">
    @foreach (['index'=>['/', 'Главная'], 'raid'=>['/raid', 'Карты'], 'documents'=>['/documents', 'Документы'], 'quests'=>['/quests', 'Квесты'], 'reference'=>['/reference', 'Справочник'], 'important-items'=>['/important-items', 'Важные предметы']] as $section => $link)
    <a href="{{ $link[0] }}" @if ($page === $section || ($page === 'boss' && $section === 'reference')) aria-current="page" @endif>{{ $link[1] }}</a>
    @endforeach
  </nav>
  <div class="header-right"><a class="button" href="/account">Личный кабинет</a></div>
</header>
