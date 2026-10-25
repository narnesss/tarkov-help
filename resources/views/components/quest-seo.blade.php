<div class="seo-reading" @if (isset($serverContent['task'])) data-server-quest="{{ $serverContent['task']['id'] }}" data-server-mode="{{ $serverContent['mode'] }}" @else data-seo-fallback @endif>
<nav class="quest-breadcrumbs" aria-label="Путь к странице">
@foreach ($seo['breadcrumbs'] as $crumb)<a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>@if (!$loop->last)<span aria-hidden="true">›</span>@endif @endforeach
</nav>
@if (isset($serverContent['task']))
@php($task = $serverContent['task'])
<h1>{{ $task['name'] }}</h1>
<p>{{ $serverContent['mode'] === 'regular' ? 'PVP' : 'PVE' }} · Escape from Tarkov
@if ($serverContent['trader']['name']) · <a href="{{ $serverContent['traderUrl'] }}">{{ $serverContent['trader']['name'] }}</a>@endif</p>
<nav aria-label="Режим игры">@foreach ($serverContent['modeLinks'] as $variant)<a href="{{ $variant['url'] }}" @if ($variant['current']) aria-current="page" @endif>{{ $variant['name'] }}</a> @endforeach</nav>
@if (isset($task['minPlayerLevel']))<p>Необходимый уровень: {{ $task['minPlayerLevel'] }}</p>@endif
@if ($serverContent['requirementLines'])<h2>Условия получения</h2><ul>@foreach ($serverContent['requirementLines'] as $line)<li>{{ $line }}</li>@endforeach</ul>@endif
<h2>Цели задания</h2>
@if ($task['objectives'])<ul>@foreach ($task['objectives'] as $goal)<li><p>{{ $goal['description'] ?? '' }}@if (!empty($goal['optional'])) (необязательно)@endif</p>
@if (isset($goal['count']))<p>Количество: {{ $goal['count'] }}@if (!empty($goal['foundInRaid'])) · Найдено в рейде@endif</p>@endif
@foreach ($goal['mapLinks'] as $map)<p><a href="{{ $map['url'] }}">{{ $map['name'] }} — показать на карте</a></p>@endforeach
@foreach ($goal['guide'] ?? [] as $line)@if (is_string($line))<p>{{ $line }}</p>@endif @endforeach
</li>@endforeach</ul>@else<p>Цели для выбранного режима не указаны.</p>@endif
@if ($serverContent['links'])<h2>Предыдущие задания</h2><ul>@foreach ($serverContent['links'] as $link)<li><a href="{{ $link['url'] }}">{{ $link['name'] }}</a>@if ($link['status']) · Требуется статус: {{ $link['status'] }}@endif</li>@endforeach</ul>@endif
@foreach ($serverContent['materials'] as $material)
<section><h2>{{ $material['title'] }}</h2>@foreach ($material['paragraphs'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
@if (!empty($material['summary']))<p>Краткий пересказ. Это не дословная реплика из игры.</p>@endif
@if (!empty($material['original']))<details><summary>Английский оригинал</summary>@foreach ($material['original'] as $paragraph)<p lang="en">{{ $paragraph }}</p>@endforeach</details>@endif
@if (!empty($material['license']))<p>Материалы Wiki · CC BY-NC-SA 3.0 · <a href="/licenses/GUIDE-DATA.txt">Авторы и условия использования</a></p>@endif
@if (!empty($material['source']))<p><a href="{{ $material['source'] }}" rel="noopener noreferrer">Источник материала</a></p>@endif
</section>
@endforeach
<h2>Награды</h2>
@foreach ($serverContent['rewardGroups'] as $group)<section><h3>{{ $group['title'] }}</h3>
@if ($group['lines'])<ul>@foreach ($group['lines'] as $line)<li>{{ $line }}</li>@endforeach</ul>@else<p>Награды не указаны в источнике.</p>@endif
@if (!empty($group['source']))<p><a href="{{ $group['source'] }}" rel="noopener noreferrer">Источник наград</a></p>@endif
</section>@endforeach
@if ($serverContent['sources'])<h2>Источники и карты</h2><ul>@foreach ($serverContent['sources'] as $source)<li><a href="{{ $source['url'] }}" rel="noopener noreferrer">{{ $source['name'] }}</a></li>@endforeach</ul>@endif
<p><a href="/quests{{ $serverContent['mode'] === 'regular' ? '?mode=regular' : '' }}">Все квесты Таркова</a></p>
@else
<h2>Каталог заданий · {{ $serverContent['mode'] === 'regular' ? 'PVP' : 'PVE' }}</h2>
@if ($serverContent['quests'])<ul class="seo-quest-list">@foreach ($serverContent['quests'] as $quest)<li><a href="{{ $quest['url'] }}">{{ $quest['name'] }}</a></li>@endforeach</ul>
@else<p>Опубликованных заданий пока нет.</p>@endif
<nav aria-label="Страницы каталога">
@if ($serverContent['previousUrl'])<a href="{{ $serverContent['previousUrl'] }}">Предыдущая страница</a>@endif
@if ($serverContent['nextUrl'])<a href="{{ $serverContent['nextUrl'] }}">Следующая страница</a>@endif
</nav>
@endif
</div>
