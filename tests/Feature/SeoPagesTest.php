<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset, Quest};
use App\Services\{PageSeo, QuestSeo};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Проверяет публичный HTML робота, актуальную публикацию квестов и sitemap без личных данных. */
final class SeoPagesTest extends TestCase
{
    use RefreshDatabase;

    private const QUEST_ID = '5936d90786f7742b1420ba5b';
    private const TRADER_ID = '54cb50c76803fa8b248b4571';

    protected function setUp(): void
    {
        parent::setUp();
        config(['site.url'=>'https://wiki.test', 'api.proxy_required'=>false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00:00', 'UTC'));
    }

    private function quest(string $id = self::QUEST_ID, string $mode = 'pve', array $extra = [], bool $published = true): Quest
    {
        $payload = array_replace([
            'id'=>$id, 'name'=>'Проверочное задание', 'minPlayerLevel'=>0, 'experience'=>1200,
            'trader'=>['id'=>self::TRADER_ID, 'name'=>'Прапор'],
            'objectives'=>[['id'=>'goal-'.$id, 'description'=>'Найти предмет и передать торговцу', 'type'=>'giveItem', 'count'=>2]],
            'finishRewards'=>['items'=>[['item'=>['id'=>'reward', 'name'=>'Наградной предмет'], 'count'=>2]]],
        ], $extra);
        return Quest::withoutEvents(fn () => Quest::create([
            'quest_id'=>$id, 'mode'=>$mode, 'name'=>$payload['name'], 'trader_id'=>$payload['trader']['id'],
            'published'=>$published, 'payload'=>$payload,
        ]));
    }

    private function url(string $path, array $query = []): string
    { return '/api/content/seo?'.http_build_query(['path'=>$path, ...$query]); }

    private function document(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">'.$html); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return new \DOMXPath($dom);
    }

    public function test_quest_html_has_escaped_content_metadata_rewards_and_prerequisite_links_without_session(): void
    {
        $this->quest('seo-previous', extra:['name'=>'Предыдущее задание']);
        $name = 'Проверка «0» <img src=x onerror=alert(1)> & "кавычки"';
        $this->quest(extra:['name'=>$name, 'taskRequirements'=>[['task'=>['id'=>'seo-previous'], 'status'=>['complete']]]]);
        $response = $this->getJson($this->url('/quests/debut', ['mode'=>'pve', 'page'=>1]))
            ->assertOk()->assertJsonPath('seoVersion', 1)->assertJsonPath('status', 200)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Set-Cookie');
        $html = $response->json('html'); $xpath = $this->document($html);
        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertSame($name, $xpath->query('//h1')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//img[@onerror]')->length);
        $this->assertStringContainsString($name, $xpath->query('//title')->item(0)->textContent);
        $this->assertSame('https://wiki.test/quests/debut', $xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'));
        foreach (['Найти предмет и передать торговцу', 'Наградной предмет', 'Предыдущее задание'] as $text) {
            $this->assertStringContainsString($text, $xpath->document->textContent);
        }
        $this->assertGreaterThan(0, $xpath->query('//a[contains(@href,"/quests/seo-previous")]')->length);
        $this->assertDatabaseCount('users', 0); $this->assertDatabaseCount('user_state', 0);
    }

    public function test_legacy_id_and_slug_share_canonical_while_modes_and_new_database_quests_stay_separate(): void
    {
        $this->quest(extra:['name'=>'Только PVE']);
        $this->quest(mode:'regular', extra:['name'=>'Только PVP']);
        foreach (['debut', self::QUEST_ID] as $alias) {
            $html = $this->getJson($this->url('/quests/'.$alias))->assertOk()->json('html');
            $this->assertStringContainsString('Только PVE', $html);
            $this->assertStringNotContainsString('Только PVP', $html);
            $this->assertSame('https://wiki.test/quests/debut', $this->document($html)->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'));
        }
        $html = $this->getJson($this->url('/quests/debut', ['mode'=>'regular']))->assertOk()->json('html');
        $this->assertStringContainsString('Только PVP', $html); $this->assertStringNotContainsString('Только PVE', $html);
        $this->assertSame('https://wiki.test/quests/debut?mode=regular', $this->document($html)->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'));
        $this->quest('seo-new-id', extra:['name'=>'Новый квест без статического маршрута']);
        $this->getJson($this->url('/quests/seo-new-id'))->assertOk();
        $this->getJson($this->url('/quests/seo-new-id', ['mode'=>'regular']))->assertNotFound();
    }

    public function test_edit_and_unpublish_are_visible_immediately_even_for_a_route_existing_in_export(): void
    {
        $quest = $this->quest(); $url = $this->url('/quests/debut');
        $this->getJson($url)->assertOk();
        $quest->name = 'Исправленная карточка'; $payload = $quest->payload; $payload['name'] = $quest->name; $quest->payload = $payload;
        Quest::withoutEvents(fn () => $quest->save());
        $this->assertStringContainsString('Исправленная карточка', $this->getJson($url)->assertOk()->json('html'));
        $quest->published = false; Quest::withoutEvents(fn () => $quest->save());
        $this->getJson($url)->assertNotFound()->assertJsonPath('seoVersion', 1)->assertJsonPath('status', 404)->assertHeaderMissing('Set-Cookie');
        $this->getJson($this->url('/quests/'.self::QUEST_ID))->assertNotFound();
        $this->getJson($this->url('/quests/missing-seo-quest'))->assertNotFound();
    }

    public function test_catalog_paginates_published_rows_and_applies_trader_and_mode_filters(): void
    {
        for ($i = 0; $i < 101; $i++) { $this->quest('seo-list-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), extra:['name'=>'Каталог '.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]); }
        $this->quest('seo-hidden', extra:['name'=>'Скрытая запись'], published:false);
        $this->quest('seo-pvp-only', mode:'regular', extra:['name'=>'Чужой режим']);
        $first = $this->getJson($this->url('/quests/all'))->assertOk()->json('html');
        $second = $this->getJson($this->url('/quests/all', ['page'=>2]))->assertOk()->json('html');
        $this->assertSame(100, $this->document($first)->query('//a[contains(@href,"/quests/seo-list-")]')->length);
        $this->assertSame(1, $this->document($second)->query('//a[contains(@href,"/quests/seo-list-")]')->length);
        $directory = $this->document($second);
        $this->assertSame(0, $directory->query('//header//button | //header//select | //header//*[@data-game-clock]')->length);
        $this->assertSame(1, $directory->query('//header//a[@href="/account"]')->length);
        $this->assertSame('PVE', $directory->query('//main//nav[@aria-label="Игровой режим"]//a[@aria-current="page"]')->item(0)->textContent);
        $this->assertSame('/quests/all?mode=regular', $directory->query('//main//nav[@aria-label="Игровой режим"]//a[.="PVP"]')->item(0)->getAttribute('href'));
        $previous = $this->document($second)->query('//nav[@aria-label="Страницы каталога"]//a[contains(.,"Предыдущая")]')->item(0);
        $this->assertNotNull($previous);
        $this->assertSame('/quests/all', parse_url($previous->getAttribute('href'), PHP_URL_PATH));
        $this->assertEmpty(parse_url($previous->getAttribute('href'), PHP_URL_QUERY));
        $this->assertStringNotContainsString('Скрытая запись', $first.$second);
        $this->assertStringNotContainsString('Чужой режим', $first.$second);
        $this->getJson($this->url('/quests/all', ['page'=>3]))->assertNotFound();
        $this->getJson($this->url('/quests', ['mode'=>'regular']))->assertOk();
        $trader = $this->getJson($this->url('/quests/trader/prapor'))->assertOk()->json('html');
        $this->assertStringContainsString('Каталог 000', $trader);
        $this->quest('seo-other-trader', extra:['name'=>'Другой торговец', 'trader'=>['id'=>'seo-other', 'name'=>'Другой']]);
        $this->assertStringNotContainsString('Другой торговец', $this->getJson($this->url('/quests/trader/prapor'))->assertOk()->json('html'));
        Quest::where('mode', 'pve')->update(['mode'=>'regular']);
        $regular = $this->getJson($this->url('/quests/all', ['mode'=>'regular']))->assertOk()->json('html');
        $next = $this->document($regular)->query('//nav[@aria-label="Страницы каталога"]//a[contains(.,"Следующая")]')->item(0);
        $this->assertNotNull($next); parse_str((string) parse_url($next->getAttribute('href'), PHP_URL_QUERY), $nextQuery);
        $this->assertEquals(['mode'=>'regular', 'page'=>'2'], $nextQuery);
        $regular = $this->getJson($this->url('/quests/all', ['mode'=>'regular', 'page'=>2]))->assertOk()->json('html');
        $directory = $this->document($regular);
        $this->assertSame(0, $directory->query('//header//button | //header//select | //header//*[@data-game-clock]')->length);
        $this->assertSame('PVP', $directory->query('//main//nav[@aria-label="Игровой режим"]//a[@aria-current="page"]')->item(0)->textContent);
        $this->assertSame('/quests/all', $directory->query('//main//nav[@aria-label="Игровой режим"]//a[.="PVE"]')->item(0)->getAttribute('href'));
        $previous = $this->document($regular)->query('//nav[@aria-label="Страницы каталога"]//a[contains(.,"Предыдущая")]')->item(0);
        $this->assertNotNull($previous); parse_str((string) parse_url($previous->getAttribute('href'), PHP_URL_QUERY), $previousQuery);
        $this->assertSame(['mode'=>'regular'], $previousQuery);
    }

    public function test_catalog_sections_do_not_mix_story_archive_traders_or_unknown_traders(): void
    {
        $this->quest('seo-ordinary', extra:['name'=>'Обычное задание']);
        $this->quest('seo-story', extra:['name'=>'Сюжетное задание', 'story'=>true]);
        $this->quest('seo-archive', extra:['name'=>'Архивное задание', 'catalogSection'=>'supplementary']);
        foreach (['story'=>['Сюжетное задание', 'Обычное задание', 'Архивное задание'], 'archive'=>['Архивное задание', 'Сюжетное задание', 'Обычное задание']] as $section=>$names) {
            $html = $this->getJson($this->url('/quests/'.$section))->assertOk()->json('html');
            $this->assertStringContainsString($names[0], $html);
            $this->assertStringNotContainsString($names[1], $html); $this->assertStringNotContainsString($names[2], $html);
        }
        $all = $this->getJson($this->url('/quests/all'))->assertOk()->json('html');
        $this->assertStringContainsString('Обычное задание', $all); $this->assertStringNotContainsString('Архивное задание', $all);
        $trader = $this->getJson($this->url('/quests/trader/prapor'))->assertOk()->json('html');
        $this->assertStringContainsString('Обычное задание', $trader);
        $this->assertStringNotContainsString('Сюжетное задание', $trader); $this->assertStringNotContainsString('Архивное задание', $trader);
        $trader = $this->getJson($this->url('/quests/trader/prapor', ['section'=>'archive']))->assertOk()->json('html');
        $this->assertStringContainsString('Обычное задание', $trader); $this->assertStringNotContainsString('Архивное задание', $trader);
        $this->getJson($this->url('/quests/trader/seo-missing-trader'))->assertNotFound();
    }

    public function test_empty_catalog_and_known_trader_are_readable_but_nonexistent_pages_are_404(): void
    {
        foreach (['/quests', '/quests/all', '/quests/trader/prapor'] as $path) {
            $this->getJson($this->url($path))->assertOk()->assertJsonPath('status', 200);
            $this->getJson($this->url($path, ['page'=>2]))->assertNotFound();
        }
        $this->quest('seo-zero', extra:['name'=>str_repeat('Длинное название ', 12), 'experience'=>0, 'objectives'=>[], 'finishRewards'=>[]]);
        $this->getJson($this->url('/quests/seo-zero'))->assertOk();
    }

    public function test_trader_rename_uses_live_dataset_without_changing_slug_or_repeating_title_prefix(): void
    {
        $this->quest();
        $dataset = new ContentDataset(['path'=>'guide/quests-pve.json']);
        $url = $this->url('/quests/trader/prapor');
        foreach (['Снабженец «0»', 'Новое имя снабженца'] as $name) {
            $dataset->setContent(['traders'=>[['id'=>self::TRADER_ID, 'name'=>$name]]]);
            ContentDataset::withoutEvents(fn () => $dataset->save());
            $content = app(QuestSeo::class)->content(['trader'=>self::TRADER_ID]);
            $this->assertSame('Квесты: '.$name, $content['title']);
            $html = $this->getJson($url)->assertOk()->json('html'); $xpath = $this->document($html);
            $title = $xpath->query('//title')->item(0)->textContent;
            $this->assertStringContainsString('Квесты: '.$name, $title);
            $this->assertSame(1, substr_count($title, 'Квесты:'));
            $this->assertSame('https://wiki.test/quests/trader/prapor', $xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'));
        }
        $this->assertStringNotContainsString('Снабженец «0»', $title);
    }

    public function test_new_trader_without_globals_gets_name_from_active_published_quest_in_selected_mode(): void
    {
        $traderId = 'seo-new-trader';
        $this->quest('seo-hidden-trader-name', extra:['name'=>'А скрытый', 'trader'=>['id'=>$traderId, 'name'=>'Неопубликованное имя']], published:false);
        $this->quest('seo-archived-trader-name', extra:['name'=>'Б архивный', 'trader'=>['id'=>$traderId, 'name'=>'Архивное имя'], 'cmsArchived'=>true]);
        $quest = $this->quest('seo-new-trader-quest', extra:['trader'=>['id'=>$traderId, 'name'=>'Новый торговец']]);
        $this->quest('seo-new-trader-quest', mode:'regular', extra:['trader'=>['id'=>$traderId, 'name'=>'Имя торговца PVP']]);
        foreach (['pve'=>'Новый торговец', 'regular'=>'Имя торговца PVP'] as $mode=>$name) {
            $content = app(QuestSeo::class)->content(['trader'=>$traderId, 'mode'=>$mode]);
            $this->assertSame('Квесты: '.$name, $content['title']);
            $html = $this->getJson($this->url('/quests/trader/'.$traderId, ['mode'=>$mode]))->assertOk()->json('html');
            $title = $this->document($html)->query('//title')->item(0)->textContent;
            $this->assertStringContainsString($name, $title);
            $this->assertStringNotContainsString('Неопубликованное имя', $title); $this->assertStringNotContainsString('Архивное имя', $title);
        }
        $payload = $quest->payload; $payload['trader']['name'] = 'Исправленное имя'; $quest->payload = $payload;
        Quest::withoutEvents(fn () => $quest->save());
        $this->assertSame('Квесты: Исправленное имя', app(QuestSeo::class)->content(['trader'=>$traderId])['title']);
    }

    public function test_catalog_titles_are_plain_and_live_metadata_keeps_mode_and_page_only_for_lists(): void
    {
        foreach ([''=>'Квесты Таркова', 'all'=>'Все квесты', 'story'=>'Основной сюжет', 'archive'=>'Архив квестов'] as $section=>$title) {
            $this->assertSame($title, app(QuestSeo::class)->content($section === '' ? [] : ['section'=>$section])['title']);
        }
        $seo = app(PageSeo::class);
        foreach (['quests', 'reference', 'boss', 'raid', 'documents'] as $page) {
            $params = ['mode'=>'regular', 'page'=>2];
            $meta = $seo->metadata($page, $params, 'Новый раздел | Tarkov Compass');
            $this->assertStringStartsWith('Новый раздел', $meta['title']);
            $this->assertSame(1, substr_count($meta['title'], 'Tarkov Compass'));
            $this->assertStringContainsString('PVP', $meta['title']); $this->assertStringContainsString('страница 2', $meta['title']);
            $this->assertSame('https://wiki.test/'.$page.'?mode=regular&page=2', $meta['canonical']);
            $this->assertSame('Новый раздел', $meta['breadcrumbs'][count($meta['breadcrumbs']) - 1]['name']);
        }
        $meta = $seo->metadata('reference', ['tab'=>'items', 'mode'=>'regular', 'page'=>2], 'Новый каталог предметов');
        $this->assertStringStartsWith('Новый каталог предметов', $meta['title']);
        $this->assertSame('https://wiki.test/reference/items?mode=regular&page=2', $meta['canonical']);
        foreach (['quest'=>['quest'=>'seo-new-id'], 'reference'=>['item'=>'seo-new-item'], 'boss'=>['boss'=>'seo-new-boss'],
            'raid'=>['map'=>'woods'], 'documents'=>['map'=>'woods']] as $page=>$params) {
            $meta = $seo->metadata($page, $params + ['mode'=>'regular', 'page'=>2], 'Название карточки');
            $this->assertStringEndsWith('?mode=regular', $meta['canonical']);
            $this->assertStringNotContainsString('page=', $meta['canonical']); $this->assertStringNotContainsString('страница 2', $meta['title']);
        }
    }

    public function test_guide_content_license_goal_modes_and_editor_reward_precedence_are_preserved(): void
    {
        $quest = $this->quest(extra:[
            'objectives'=>[['id'=>'pvp', 'description'=>'Цель только PVP', 'modes'=>['regular']], ['id'=>'pve', 'description'=>'Цель только PVE', 'modes'=>['pve']], ['id'=>'archived', 'description'=>'Архивная цель', 'cmsArchived'=>true]],
            'questContent'=>['briefing'=>['kind'=>'summary', 'paragraphs'=>['Краткий брифинг'], 'original'=>['Original briefing']],
                'walkthrough'=>['paragraphs'=>['Авторское прохождение'], 'sourceUrl'=>'https://escapefromtarkov.fandom.com/ru/wiki/Test'],
                'rewards'=>['policy'=>'completion', 'lines'=>['Награда из Wiki']]],
        ]);
        $url = $this->url('/quests/debut'); $html = $this->getJson($url)->assertOk()->json('html');
        foreach (['Краткий брифинг', 'Авторское прохождение', 'Награда из Wiki', 'Цель только PVE'] as $text) { $this->assertStringContainsString($text, $html); }
        foreach (['Цель только PVP', 'Архивная цель'] as $text) { $this->assertStringNotContainsString($text, $html); }
        $this->assertGreaterThan(0, $this->document($html)->query('//a[contains(@href,"/licenses/GUIDE-DATA.txt")]')->length);
        $payload = $quest->payload; $payload['cmsRewards'] = true;
        $payload['finishRewards'] = ['items'=>[['item'=>['id'=>'editor-reward', 'name'=>'Редакторская награда'], 'count'=>1]]];
        $quest->payload = $payload; Quest::withoutEvents(fn () => $quest->save());
        $html = $this->getJson($url)->assertOk()->json('html');
        $this->assertStringContainsString('Редакторская награда', $html); $this->assertStringNotContainsString('Награда из Wiki', $html);
    }

    public function test_sitemap_uses_live_published_rows_modes_dates_and_canonical_slugs(): void
    {
        $quest = $this->quest(); $this->quest(mode:'regular');
        $this->quest('seo-new-id'); $this->quest('seo-hidden', published:false);
        $response = $this->getJson($this->url('/sitemaps/quests.xml'))->assertOk()->assertJsonPath('seoVersion', 1)
            ->assertJsonPath('status', 200)->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Set-Cookie');
        $xml = simplexml_load_string($response->json('xml')); $this->assertNotFalse($xml);
        $locations = array_map('strval', $xml->xpath('//*[local-name()="loc"]'));
        $this->assertContains('https://wiki.test/quests/debut', $locations);
        $this->assertContains('https://wiki.test/quests/debut?mode=regular', $locations);
        $this->assertContains('https://wiki.test/quests/seo-new-id', $locations);
        $this->assertNotContains('https://wiki.test/quests/'.self::QUEST_ID, $locations);
        $this->assertNotContains('https://wiki.test/quests/seo-hidden', $locations);
        $this->assertStringContainsString('2026-10-25', $response->json('xml'));
        $quest->published = false; Quest::withoutEvents(fn () => $quest->save());
        $xml = simplexml_load_string($this->getJson($this->url('/sitemaps/quests.xml'))->assertOk()->json('xml'));
        $locations = array_map('strval', $xml->xpath('//*[local-name()="loc"]'));
        $this->assertNotContains('https://wiki.test/quests/debut', $locations);
        $this->assertContains('https://wiki.test/quests/debut?mode=regular', $locations);
    }

    public function test_sitemap_index_and_static_pages_exclude_private_pages_and_aliases(): void
    {
        $index = $this->getJson($this->url('/sitemap.xml'))->assertOk()->assertJsonPath('seoVersion', 1)->json('xml');
        $xml = simplexml_load_string($index); $this->assertNotFalse($xml);
        $this->assertEqualsCanonicalizing(['https://wiki.test/sitemaps/quests.xml', 'https://wiki.test/sitemaps/pages.xml', 'https://wiki.test/sitemaps/items-pve.xml', 'https://wiki.test/sitemaps/items-regular.xml'], array_map('strval', $xml->xpath('//*[local-name()="loc"]')));
        $pages = $this->getJson($this->url('/sitemaps/pages.xml'))->assertOk()->json('xml');
        $xml = simplexml_load_string($pages); $this->assertNotFalse($xml);
        $locations = array_map('strval', $xml->xpath('//*[local-name()="loc"]'));
        $this->assertContains('https://wiki.test/', $locations);
        $this->assertSame(count($locations), count(array_unique($locations)));
        foreach ($locations as $location) { $this->assertDoesNotMatchRegularExpression('~/(?:account|admin|api|404|quest-map-source|index)(?:[/.?]|$)|\.html(?:[?]|$)~', $location); }
    }

    public function test_archived_payload_is_absent_from_catalog_and_sitemap_and_returns_404(): void
    {
        $this->quest(extra:['name'=>'Уже архивированное задание', 'cmsArchived'=>true]);
        $this->getJson($this->url('/quests/debut'))->assertNotFound();
        foreach (['/quests', '/quests/all', '/quests/trader/prapor'] as $path) {
            $this->assertStringNotContainsString('Уже архивированное задание', $this->getJson($this->url($path))->assertOk()->json('html'));
        }
        $xml = simplexml_load_string($this->getJson($this->url('/sitemaps/quests.xml'))->assertOk()->json('xml'));
        $this->assertNotContains('https://wiki.test/quests/debut', array_map('strval', $xml->xpath('//*[local-name()="loc"]')));
    }

    public function test_invalid_query_types_values_and_paths_return_422_instead_of_server_errors(): void
    {
        foreach (['path[]=/quests', 'path=https://evil.test/quests', 'path=/../quests', 'path=/quests&mode[]=pve', 'path=/quests&mode=other',
            'path=/quests&page[]=1', 'path=/quests&page=0', 'path=/quests&page=-1', 'path=/quests&page=1.5', 'path=/quests&page=999999999999999999999'] as $query) {
            $this->getJson('/api/content/seo?'.$query)->assertUnprocessable();
        }
        $this->assertDatabaseCount('users', 0); $this->assertDatabaseCount('user_state', 0);
    }

    public function test_proxy_secret_is_required_when_enabled_and_success_does_not_create_cookies(): void
    {
        $this->quest(); config(['api.proxy_required'=>true, 'api.proxy_token'=>str_repeat('s', 64)]);
        $url = $this->url('/quests/debut'); $this->getJson($url)->assertForbidden();
        $this->getJson($url, ['X-Wiki-Proxy-Token'=>'forged'])->assertForbidden();
        $this->getJson($url, ['X-Wiki-Proxy-Token'=>str_repeat('s', 64), 'X-Wiki-Client-IP'=>'198.51.100.8'])
            ->assertOk()->assertHeaderMissing('Set-Cookie')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('users', 0); $this->assertDatabaseCount('user_state', 0);
    }
}
