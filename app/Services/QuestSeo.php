<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{ContentDataset, Quest};
use Illuminate\Support\Facades\Cache;

/** Читает опубликованные задания для HTML робота и посетителя из действующего источника. */
final class QuestSeo
{
    public function content(array $params): array
    {
        $mode = $params['mode'] ?? 'pve';
        $query = Quest::where('mode', $mode)->where('published', true);
        $index = json_decode(file_get_contents(public_path('data/page-routes.json')), true, 64, JSON_THROW_ON_ERROR);
        if (isset($params['quest'])) {
            $quest = (clone $query)->where('quest_id', $params['quest'])->first();
            abort_unless($quest, 404);
            return $this->detail($quest, $mode, $index);
        }
        $trader = $params['trader'] ?? null; $traderName = '';
        if ($trader !== null) {
            $liveName = $this->reference($trader, $this->globals($mode, [])['traders'] ?? [])['name'];
            $traderName = is_string($liveName) ? $liveName : '';
            $known = $traderName !== '' || isset($index['traders'][$trader]) || (clone $query)->where('trader_id', $trader)->exists();
            abort_unless($known, 404);
            $query->where('trader_id', $trader);
        }
        $section = $trader !== null ? 'all' : ($params['section'] ?? 'all'); $number = (int) ($params['page'] ?? 1); $total = 0; $quests = [];
        // В SQL хранятся ссылки на файлы. Кэшируются только признаки конкретной версии,
        // поэтому обновление одного задания не требует повторного чтения остальных файлов.
        foreach ($query->orderBy('name')->orderBy('quest_id')->lazy(100) as $row) {
            $flags = $this->flags($row);
            if (!empty($flags['cmsArchived'])) { continue; }
            if ($trader !== null && $traderName === '') {
                $reference = PublicJson::select($row->json(), ['trader'])['trader'] ?? null;
                if (is_array($reference) && in_array($this->id($reference), ['', $trader], true) && is_string($reference['name'] ?? null)) {
                    $traderName = $reference['name'];
                }
            }
            $archive = ($flags['catalogSection'] ?? null) === 'supplementary';
            if ($archive !== ($section === 'archive') || ($section === 'story' && empty($flags['story'])) || ($trader !== null && !empty($flags['story']))) { continue; }
            if ($total >= ($number - 1) * 100 && count($quests) < 100) {
                $quests[] = ['name'=>$row->name, 'url'=>$this->questPath($row->quest_id, $mode, $index)];
            }
            $total++;
        }
        $pages = max(1, (int) ceil($total / 100)); abort_if($number > $pages, 404);
        $title = $trader !== null ? ($traderName !== '' ? 'Квесты: '.$traderName : 'Квесты торговца')
            : (isset($params['section']) ? ['all'=>'Все квесты', 'story'=>'Основной сюжет', 'archive'=>'Архив квестов'][$section] : 'Квесты Таркова');
        return compact('quests', 'mode', 'number', 'total', 'pages', 'title') + [
            'previousUrl'=>$number > 1 ? $this->catalogPath($params, $number - 1, $index) : null,
            'nextUrl'=>$number < $pages ? $this->catalogPath($params, $number + 1, $index) : null,
        ];
    }

    /** Признаки для каталога и sitemap; ключ меняется вместе с SQL-ссылкой на контент. */
    public function flags(Quest $quest): array
    {
        $scope = serialize([$quest->getConnection()->getDatabaseName(), config('content.directory'), $quest->revision()]);
        return Cache::remember('seo:quest-flags:'.hash('sha256', $scope), config('content.public_cache_ttl', 86400),
            fn () => PublicJson::select($quest->json(), ['story', 'catalogSection', 'cmsArchived']));
    }

    private function detail(Quest $quest, string $mode, array $index): array
    {
        $task = $quest->payload; abort_if(!empty($task['cmsArchived']), 404); $task['name'] = $quest->name;
        $task['objectives'] = array_values(array_filter($task['objectives'] ?? [], static fn ($goal) => empty($goal['cmsArchived']) && (!isset($goal['modes']) || in_array($mode, $goal['modes'], true))));
        $globals = $this->globals($mode, $task);
        $trader = $this->reference($task['trader'] ?? $quest->trader_id, $globals['traders'] ?? []);
        $requirements = []; $links = [];
        foreach ($task['taskRequirements'] ?? [] as $requirement) {
            $id = $this->id($requirement['task'] ?? null);
            if ($id !== '') { $requirements[$id] = $requirement; }
        }
        foreach (Quest::where('mode', $mode)->where('published', true)->whereIn('quest_id', array_keys($requirements))->cursor() as $previous) {
            if (!empty($this->flags($previous)['cmsArchived'])) { continue; }
            $states = $requirements[$previous->quest_id]['status'] ?? ['complete'];
            $labels = ['complete'=>'Выполнено', 'completed'=>'Выполнено', 'started'=>'В процессе', 'active'=>'В процессе', 'availableForFinish'=>'Готово к сдаче', 'failed'=>'Провалено', 'available'=>'Доступно'];
            $links[] = ['name'=>$previous->name, 'url'=>$this->questPath($previous->quest_id, $mode, $index), 'status'=>implode(' или ', array_map(static fn ($state) => $labels[$state] ?? $state, $states))];
        }
        foreach ($task['objectives'] as &$goal) {
            $goal['mapLinks'] = [];
            $maps = [...($goal['maps'] ?? []), ...array_column($goal['zones'] ?? [], 'map')];
            foreach ($maps as $value) {
                $map = $this->reference($value, $globals['maps'] ?? []); $slug = $map['normalizedName'] ?? $map['id'];
                if (isset($index['maps'][$slug])) { $goal['mapLinks'][$slug] = ['name'=>$map['name'] ?: $index['maps'][$slug]['name'], 'url'=>'/raid/'.rawurlencode($slug).'?'.http_build_query(['quest'=>$quest->quest_id, 'mode'=>$mode])]; }
            }
        } unset($goal);
        $requirementLines = $this->lines($task['storyRequirements'] ?? []);
        foreach ($task['otherRequirements'] ?? [] as $line) { $text = is_string($line) ? $line : ($line['description'] ?? null); if (is_string($text) && $text !== '') { $requirementLines[] = $text; } }
        foreach ($task['traderRequirements'] ?? [] as $requirement) {
            $name = $this->reference($requirement['trader'] ?? null, $globals['traders'] ?? [])['name'];
            $kind = ['level'=>'уровень лояльности', 'reputation'=>'репутация', 'standing'=>'репутация'][$requirement['requirementType'] ?? ''] ?? 'условие';
            $requirementLines[] = ($name ?: 'Торговец').': '.$kind.' '.($requirement['compareMethod'] ?? '≥').' '.($requirement['value'] ?? '');
        }
        if (!empty($task['factionName']) && !in_array($task['factionName'], ['Any', 'AnyPmc'], true)) { $requirementLines[] = 'Фракция: '.$task['factionName']; }
        $materials = [];
        if (is_string($task['description'] ?? null) && $task['description'] !== '') { $materials[] = ['title'=>'Описание', 'paragraphs'=>preg_split('/\n\s*\n/u', $task['description'])]; }
        $briefing = $task['questContent']['briefing'] ?? [];
        if ($paragraphs = $this->lines($briefing['paragraphs'] ?? [])) {
            $materials[] = ['title'=>'Брифинг', 'paragraphs'=>$paragraphs, 'summary'=>($briefing['kind'] ?? '') === 'summary',
                'original'=>$this->lines($briefing['original'] ?? []), 'license'=>($briefing['kind'] ?? '') !== 'summary' || !empty($briefing['original']),
                'source'=>$this->safeUrl($task['questContent']['wikiUrl'] ?? null)];
        }
        $walkthrough = $task['questContent']['walkthrough'] ?? [];
        if ($paragraphs = $this->lines(is_string($walkthrough) ? [$walkthrough] : ($walkthrough['paragraphs'] ?? []))) {
            $source = $this->safeUrl(is_array($walkthrough) ? ($walkthrough['sourceUrl'] ?? null) : null);
            $materials[] = ['title'=>'Прохождение', 'paragraphs'=>$paragraphs, 'source'=>$source, 'license'=>$this->wikiSource($source)];
        }
        $additions = [];
        foreach ($task['guideAdditions'] ?? [] as $addition) { $additions = [...$additions, ...$this->lines($addition['paragraphs'] ?? [])]; }
        if ($additions) { $materials[] = ['title'=>'Ориентиры и уточнения', 'paragraphs'=>array_values(array_unique($additions))]; }
        foreach ($task['guideSections'] ?? [] as $group) { if ($paragraphs = $this->lines($group['paragraphs'] ?? [])) { $materials[] = ['title'=>$group['title'] ?? 'Материалы задания', 'paragraphs'=>$paragraphs, 'source'=>$this->safeUrl($group['sourceUrl'] ?? null)]; } }
        if ($paragraphs = $this->lines($task['storyGuide'] ?? [])) { $materials[] = ['title'=>'Материалы сюжетной главы', 'paragraphs'=>$paragraphs]; }
        $sources = [];
        foreach ([...($task['guideSources'] ?? []), ...($task['externalMaps'] ?? []), ...($task['storySources'] ?? [])] as $source) {
            if ($url = $this->safeUrl($source['url'] ?? null)) { $sources[$url] = ['url'=>$url, 'name'=>$source['label'] ?? 'Источник']; }
        }
        $modeLinks = [];
        foreach (Quest::where('quest_id', $quest->quest_id)->where('published', true)->whereIn('mode', ['pve', 'regular'])->get() as $variant) {
            if (empty($this->flags($variant)['cmsArchived'])) { $modeLinks[] = ['name'=>$variant->mode === 'regular' ? 'PVP' : 'PVE', 'url'=>$this->questPath($variant->quest_id, $variant->mode, $index), 'current'=>$variant->mode === $mode]; }
        }
        $description = $quest->name.' — задание'.($trader['name'] !== '' ? ': '.$trader['name'] : '').' в Escape from Tarkov. '.implode(' ', array_slice(array_column($task['objectives'], 'description'), 0, 2));
        return compact('task', 'trader', 'links', 'materials', 'sources', 'mode', 'modeLinks', 'requirementLines') + [
            'rewardGroups'=>$this->rewards($task, $globals, $mode),
            'description'=>mb_strimwidth(preg_replace('/\s+/u', ' ', $description), 0, 230, '…'),
            'traderUrl'=>$trader['id'] !== '' ? '/quests/trader/'.($index['traders'][$trader['id']]['slug'] ?? rawurlencode($trader['id'])).($mode === 'regular' ? '?mode=regular' : '') : null,
        ];
    }

    private function rewards(array $task, array $globals, string $mode): array
    {
        $groups = [];
        if (empty($task['cmsRewards']) && ($task['questContent']['rewards']['policy'] ?? null) === 'completion') {
            $groups[] = ['title'=>'За выполнение', 'lines'=>$this->lines($task['questContent']['rewards']['lines'] ?? [])];
        } elseif (empty($task['cmsRewards']) && isset($task['catalogRewardLines'])) {
            return [['title'=>'Награды', 'lines'=>$this->lines($task['catalogRewardLines'])]];
        } elseif (empty($task['cmsRewards']) && isset($task['media']['wikiRewards'])) {
            $wiki = $task['media']['wikiRewards'];
            $groups[] = $this->rewardGroup($wiki['finishRewards'] ?? [], $globals, 'Награды по Вики', $wiki['experience'] ?? null) + ['source'=>$this->safeUrl($wiki['sourceUrl'] ?? null)];
        } elseif (empty($task['cmsRewards']) && !empty($task['story']) && !empty($task['storyRewards'])) {
            $groups[] = ['title'=>'Награды сюжетной главы', 'lines'=>$this->lines($task['storyRewards']), 'source'=>$this->safeUrl($task['storyRewardSource'] ?? null)];
            foreach ($task['storyBranches'] ?? [] as $branch) { $groups[] = ['title'=>$branch['name'] ?? 'Вариант прохождения', 'lines'=>$this->lines($branch['rewardsByMode'][$mode] ?? $branch['rewards'] ?? []), 'source'=>$this->safeUrl($branch['rewardsSource'] ?? null)]; }
        } else { $groups[] = $this->rewardGroup($task['finishRewards'] ?? [], $globals, 'За выполнение', $task['experience'] ?? null); }
        if (empty($task['cmsRewards'])) {
            foreach (['startRewards'=>'Выдаётся при старте', 'failureOutcome'=>'При провале'] as $key=>$title) { if (!empty($task[$key])) { $groups[] = $this->rewardGroup($task[$key], $globals, $title); } }
        }
        return $groups;
    }

    private function rewardGroup(array $rewards, array $globals, string $title, mixed $experience = null): array
    {
        $lines = []; if ($experience !== null) { $lines[] = 'Опыт: '.$experience; }
        foreach ($rewards['items'] ?? [] as $reward) { $name = $this->reference($reward['item'] ?? null, [...($globals['items'] ?? []), ...($globals['questItems'] ?? [])])['name']; if ($name !== '') { $lines[] = $name.' — '.($reward['count'] ?? 1); } }
        foreach ($rewards['traderStanding'] ?? [] as $reward) { $name = $this->reference($reward['trader'] ?? null, $globals['traders'] ?? [])['name']; $lines[] = ($name ?: 'Торговец').': репутация '.($reward['standing'] ?? ''); }
        foreach ($rewards['skillLevelReward'] ?? [] as $reward) { $name = $this->reference($reward['skill'] ?? null, $globals['skills'] ?? [])['name']; if ($name !== '') { $lines[] = $name.': +'.($reward['level'] ?? 0); } }
        foreach (['traderUnlock'=>['Торговец', 'traders'], 'traderDialogueUnlock'=>['Диалог', 'traders'], 'locationUnlock'=>['Локация', 'maps'], 'achievement'=>['Достижение', 'achievements']] as $key=>[$label, $group]) {
            foreach ($rewards[$key] ?? [] as $value) { $name = $this->reference($value, $globals[$group] ?? [])['name']; if ($name !== '') { $lines[] = $label.': '.$name; } }
        }
        foreach (['offerUnlock'=>'Покупка', 'craftUnlock'=>'Крафт'] as $key=>$label) {
            foreach ($rewards[$key] ?? [] as $reward) { $name = $this->reference($reward['item'] ?? null, $globals['items'] ?? [])['name']; $owner = $this->reference($reward['trader'] ?? $reward['station'] ?? null, [...($globals['traders'] ?? []), ...($globals['hideout'] ?? [])])['name']; if ($name !== '') { $lines[] = $label.': '.$name.($owner !== '' ? ' · '.$owner : '').(isset($reward['level']) ? ', уровень '.$reward['level'] : ''); } }
        }
        return compact('title', 'lines');
    }

    private function globals(string $mode, array $task): array
    {
        $dataset = ContentDataset::where('path', 'guide/quests-'.$mode.'.json')->first();
        if (!$dataset) { return []; }
        $ids = []; $visit = function ($value) use (&$visit, &$ids) { if (is_array($value)) { foreach ($value as $child) { $visit($child); } } elseif (is_string($value)) { $ids[$value] = true; } }; $visit($task);
        return PublicJson::select($dataset->json(), ['traders', 'maps', 'skills', 'hideout', 'achievements'],
            array_fill_keys(['items', 'questItems'], static fn ($row) => isset($ids[$row['id'] ?? '']) && empty($row['cmsArchived']) ? $row : null));
    }

    private function reference(mixed $value, array $rows): array
    {
        $id = $this->id($value); $result = ['id'=>$id, 'name'=>''];
        foreach ($rows as $row) { if (($row['id'] ?? null) === $id && empty($row['cmsArchived'])) { $result = [...$result, ...$row]; break; } }
        return is_array($value) ? [...$result, ...$value] : $result;
    }

    private function id(mixed $value): string
    { return is_string($value) ? $value : (is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : ''); }

    private function lines(array $lines): array
    { return array_values(array_filter($lines, static fn ($line) => is_string($line) && trim($line) !== '')); }

    private function safeUrl(mixed $url): ?string
    { return is_string($url) && !str_contains($url, '\\') && preg_match('~^(?:https?://[^\s<>"\x00-\x20]+|/(?!/)[^\s<>"\x00-\x20]*)$~D', $url) ? $url : null; }

    private function wikiSource(?string $url): bool
    { return $url !== null && in_array(parse_url($url, PHP_URL_HOST), ['escapefromtarkov.fandom.com', 'escapefromtarkov.wiki.gg'], true); }

    private function catalogPath(array $params, int $number, array $index): string
    {
        $path = isset($params['trader']) ? '/quests/trader/'.rawurlencode($index['traders'][$params['trader']]['slug'] ?? $params['trader']) : '/quests'.(isset($params['section']) ? '/'.$params['section'] : '');
        $query = ($params['mode'] ?? 'pve') === 'regular' ? ['mode'=>'regular'] : [];
        if ($number > 1) { $query['page'] = $number; }
        return $path.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    public function questPath(string $id, string $mode, array $index): string
    { return '/quests/'.rawurlencode($index['quests'][$id]['slug'] ?? $id).($mode === 'regular' ? '?mode=regular' : ''); }
}
