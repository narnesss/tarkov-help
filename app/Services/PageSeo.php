<?php
declare(strict_types=1);
namespace App\Services;

/** Единые метаданные и канонические адреса публичных страниц без личного состояния. */
final class PageSeo
{
    private ?array $index = null;
    private ?string $indexHash = null;
    private bool $frozenCatalog = false;
    private const FALLBACK_ORIGIN = 'https://tarkov-compass.narnesss.workers.dev';
    private const SECTIONS = ['all'=>'Все квесты', 'story'=>'Основной сюжет', 'archive'=>'Архив квестов'];
    private const TABS = ['items'=>'Предметы', 'ammo'=>'Патроны', 'needed'=>'Предметы для квестов', 'barters'=>'Бартеры', 'crafts'=>'Крафты', 'hideout'=>'Убежище', 'bosses'=>'Боссы', 'traders'=>'Торговцы', 'achievements'=>'Достижения', 'btr'=>'Маршруты БТР'];

    public function origin(): string
    {
        $url = config('site.url', self::FALLBACK_ORIGIN);
        $parts = is_string($url) ? parse_url($url) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            return self::FALLBACK_ORIGIN;
        }
        return rtrim($url, '/');
    }

    /** Параметры фильтров не создают самостоятельные страницы в поиске. */
    public function metadata(string $page, array $params = [], ?string $title = null): array
    {
        $meta = config('pages.'.$page);
        if (!is_array($meta)) { throw new \InvalidArgumentException('Неизвестный тип страницы.'); }
        $entityTitle = $title !== null && $title !== $meta['title'] ? $this->plainName($title) : null;
        $origin = $this->origin();
        $path = $page === 'index' ? '/' : '/'.($page === 'not-found' ? '404' : $page);
        $title = $meta['title'];
        $description = $meta['description'];
        $name = $this->plainName($title);
        $breadcrumbs = [['name'=>'Tarkov Compass', 'url'=>$origin.'/']];
        $robots = in_array($page, ['account', 'not-found', 'quest-map-source', 'quest'], true) ? 'noindex, follow' : 'index, follow';
        if ($page === 'account') { $robots = 'noindex, nofollow'; }
        $key = match ($page) {'quest'=>'quest', 'boss'=>'boss', 'raid', 'documents', 'quest-map-source'=>'map', 'reference'=>'item', default=>null};
        $group = match ($page) {'quest'=>'quests', 'boss'=>'bosses', 'raid'=>'maps', 'documents'=>'documents', 'quest-map-source'=>'sourceMaps', 'reference'=>'items', default=>null};
        if ($key !== null && array_key_exists($key, $params)) {
            $id = $this->identifier($params[$key]);
            $entry = $id === null ? null : $this->entry($group, $id);
            if ($id === null) {
                $robots = 'noindex, follow';
            } else {
                $name = $entityTitle ?? $entry['name'] ?? $id;
                $slug = $this->identifier($entry['slug'] ?? $id) ?? $id;
                $path = match ($page) {'quest'=>'/quests/'.$slug, 'reference'=>'/reference/items/'.$id, 'quest-map-source'=>'/quest-map/'.$id, default=>'/'.$page.'/'.$id};
                $title = match ($page) {
                    'quest'=>$name.' — квест Escape from Tarkov | Tarkov Compass',
                    'raid'=>$name.' — карта, выходы и квесты Таркова | Tarkov Compass',
                    'documents'=>$name.' — документы Kord Breach в Таркове | Tarkov Compass',
                    'boss'=>$name.' — босс Escape from Tarkov | Tarkov Compass',
                    'reference'=>$name.' — предмет Escape from Tarkov | Tarkov Compass',
                    default=>$name.' — карта квеста | Tarkov Compass',
                };
                $description = match ($page) {
                    'quest'=>'Квест «'.$name.'» в Escape from Tarkov: цели задания, условия открытия, награды и связанные квесты. Выберите PVE или PVP.',
                    'raid'=>'Карта «'.$name.'» в Escape from Tarkov: выходы, места появления, контейнеры и квестовые точки. Фильтры объектов и уровни карты.',
                    'documents'=>'Документы Kord Breach на локации «'.$name.'» в Escape from Tarkov: точки на карте, фотографии мест и отметки найденных документов.',
                    'boss'=>'Босс «'.$name.'» в Escape from Tarkov: сведения о здоровье и местах появления. Информация для подготовки к рейду.',
                    'reference'=>'Предмет «'.$name.'» в Escape from Tarkov: характеристики и справочные сведения для подготовки к рейду.',
                    default=>'Карта мест выполнения квеста: '.$name.'.',
                };
                if ($page === 'quest') { $robots = 'index, follow'; }
                $parent = match ($page) {'quest'=>['Квесты', '/quests'], 'reference'=>['Предметы', '/reference/items'], 'raid'=>['Карты', '/raid'], 'documents'=>['Документы', '/documents'], 'boss'=>['Боссы', '/boss'], default=>['Квесты', '/quests']};
                $breadcrumbs[] = ['name'=>$parent[0], 'url'=>$origin.$parent[1]];
            }
        } elseif ($page === 'quests' && ($trader = $this->identifier($params['trader'] ?? null)) !== null) {
            $entry = $this->entry('traders', $trader);
            $name = $entityTitle ?? 'Квесты: '.($entry['name'] ?? $trader);
            $path = '/quests/trader/'.($this->identifier($entry['slug'] ?? $trader) ?? $trader);
            $title = $name.' в Escape from Tarkov | Tarkov Compass';
            $description = $name.' в Escape from Tarkov. Цели, требования, награды и цепочки заданий торговца для PVE и PVP.';
            $breadcrumbs[] = ['name'=>'Квесты', 'url'=>$origin.'/quests'];
        } elseif ($page === 'quests' && isset(self::SECTIONS[$this->string($params['section'] ?? null)])) {
            $section = $params['section']; $name = $entityTitle ?? self::SECTIONS[$section]; $path = '/quests/'.$section;
            $title = $name.' Escape from Tarkov | Tarkov Compass';
            $description = $name.' Escape from Tarkov: каталог заданий, условия открытия, цели и награды. Отдельный прогресс в PVE и PVP.';
            $breadcrumbs[] = ['name'=>'Квесты', 'url'=>$origin.'/quests'];
        } elseif ($page === 'reference' && isset(self::TABS[$this->string($params['tab'] ?? null)])) {
            $tab = $params['tab']; $name = $entityTitle ?? self::TABS[$tab]; $path = '/reference/'.$tab;
            $title = $name.' Escape from Tarkov | Tarkov Compass';
            $description = $name.' в Escape from Tarkov: справочник Tarkov Compass с поиском и данными для подготовки к рейдам.';
            $breadcrumbs[] = ['name'=>'Справочник', 'url'=>$origin.'/reference'];
        } elseif ($entityTitle !== null && in_array($page, ['quests', 'reference', 'raid', 'documents', 'boss'], true)) {
            $name = $entityTitle;
            $title = $name.' — Escape from Tarkov | Tarkov Compass';
            $description = $name.' в Escape from Tarkov: '.match ($page) {
                'quests'=>'каталог заданий, цели, условия открытия и награды для PVE и PVP.',
                'reference'=>'справочные сведения о предметах и игровых механиках для подготовки к рейду.',
                'raid'=>'интерактивные карты с выходами, объектами и квестовыми точками.',
                'documents'=>'документы Kord Breach, локации и места на карте.',
                'boss'=>'сведения о боссах, здоровье и локациях.',
            };
        }
        $regular = in_array($page, ['quest', 'quests', 'reference', 'boss', 'raid', 'documents'], true) && ($params['mode'] ?? null) === 'regular';
        $query = $regular ? ['mode'=>'regular'] : [];
        if ($regular) { $title = str_replace(' | Tarkov Compass', ' — PVP | Tarkov Compass', $title); }
        $pageNumber = $params['page'] ?? null;
        $catalog = in_array($page, ['quests', 'reference', 'boss', 'raid', 'documents'], true)
            && !array_intersect(['quest', 'item', 'boss', 'map'], array_keys($params));
        if ($catalog && (is_string($pageNumber) || is_int($pageNumber))
            && preg_match('/^[1-9][0-9]{0,4}$/D', (string) $pageNumber) && (int) $pageNumber > 1 && (int) $pageNumber <= 10000) {
            $query['page'] = (int) $pageNumber;
            $title = str_replace(' | Tarkov Compass', ' — страница '.$pageNumber.' | Tarkov Compass', $title);
            $description .= ' Страница '.$pageNumber.'.';
        }
        if ($query !== []) { $path .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986); }
        $canonical = $page === 'not-found' ? null : $origin.$path;
        if ($page !== 'index' && $canonical !== null) { $breadcrumbs[] = ['name'=>$name, 'url'=>$canonical]; }
        $schema = [];
        if ($canonical !== null && str_starts_with($robots, 'index')) {
            $schema = ['@context'=>'https://schema.org', '@graph'=>[
                ['@type'=>'WebSite', '@id'=>$origin.'/#website', 'url'=>$origin.'/', 'name'=>'Tarkov Compass', 'inLanguage'=>'ru'],
                ['@type'=>'WebPage', '@id'=>$canonical.'#webpage', 'url'=>$canonical, 'name'=>$title, 'description'=>$description, 'inLanguage'=>'ru', 'isPartOf'=>['@id'=>$origin.'/#website']],
            ]];
            if (count($breadcrumbs) > 1) {
                $schema['@graph'][] = ['@type'=>'BreadcrumbList', 'itemListElement'=>array_map(static fn (array $crumb, int $position): array => ['@type'=>'ListItem', 'position'=>$position + 1, 'name'=>$crumb['name'], 'item'=>$crumb['url']], $breadcrumbs, array_keys($breadcrumbs))];
            }
        }
        return compact('title', 'description', 'canonical', 'robots', 'schema', 'breadcrumbs');
    }

    /** Подставляет описание из актуального серверного содержимого, включая JSON-LD. */
    public function withDescription(array $seo, string $description): array
    {
        $seo['description'] = $description;
        foreach ($seo['schema']['@graph'] ?? [] as $key => $entry) {
            if (($entry['@type'] ?? null) === 'WebPage') { $seo['schema']['@graph'][$key]['description'] = $description; }
        }
        return $seo;
    }

    /** Неизменяемый снимок каталога для одного прохода экспорта или sitemap. */
    public function catalogSnapshot(): self
    {
        $snapshot = clone $this;
        $snapshot->frozenCatalog = false;
        $snapshot->loadIndex();
        $snapshot->frozenCatalog = true;
        return $snapshot;
    }

    private function entry(string $group, string $id): ?array
    {
        $this->loadIndex();
        if (isset($this->index[$group][$id])) { return $this->index[$group][$id]; }
        foreach ($this->index[$group] ?? [] as $entry) { if (($entry['slug'] ?? null) === $id) { return $entry; } }
        return null;
    }

    private function loadIndex(): void
    {
        if ($this->frozenCatalog && $this->index !== null) { return; }
        $file = public_path('data/page-routes.json'); $hash = hash_file('sha256', $file);
        if ($hash !== $this->indexHash) {
            $this->index = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            $this->indexHash = $hash;
        }
    }

    private function identifier(mixed $value): ?string
    { return is_string($value) && preg_match('/^[a-zA-Z0-9_-]+$/D', $value) ? $value : null; }

    private function string(mixed $value): string
    { return is_string($value) ? $value : ''; }

    private function plainName(string $title): string
    { return preg_replace('/\s*(?:—|\|)\s*(?:Escape from Tarkov Wiki|Tarkov Compass)$/u', '', $title); }
}
