<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset, ContentReference, Quest, ReferenceOptions, User};
use App\Services\{ContentFields, ContentLinks, QuestEditor};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt, DB};
use Tests\TestCase;

/** Проверяет реальные формы Orchid и преобразование названий в прежние игровые связи. */
final class ContentReferencesTest extends TestCase
{
    use RefreshDatabase;

    private function dataset(string $path, array $data): ContentDataset
    {
        $dataset = ContentDataset::firstOrNew(['path' => $path]);$dataset->setContent($data);$dataset->save();return $dataset;
    }

    private function fixtures(): Quest
    {
        $this->dataset('guide/quests-pve.json', ['traders' => [['id' => 'prapor', 'name' => 'Прапор']],
            'maps' => [['id' => '5704e554d2720bac5b8b456e', 'name' => 'Таможня'], ['id' => '5704e4dad2720bb55b8b4567', 'name' => 'Маяк']]]);
        $this->dataset('guide/items-pve.json', ['items' => [
            ['id' => 'item-watch', 'name' => 'Бронзовые часы', 'normalizedName' => 'bronze-pocket-watch', 'shortName' => 'Часы', 'iconLink' => '/watch.png'],
            ['id' => 'item-key', 'name' => 'Ключ от коттеджа', 'normalizedName' => 'cottage-key', 'iconLink' => '/key.png'],
        ]]);
        $this->dataset('maps.json', [['id' => 'lighthouse', 'name' => 'Маяк']]);
        $this->dataset('docs.json', [['id' => 'technical', 'name' => 'Техническая документация']]);
        $this->actingAs(User::create(['name' => 'Редактор', 'email' => 'links@example.test', 'password' => bcrypt('test'),
            'permissions' => ['platform.index' => true, 'wiki.content' => true]]));
        return app(QuestEditor::class)->save(new Quest, ['mode' => 'pve', 'name' => 'Поиск документов', 'trader_id' => 'prapor',
            'min_player_level' => 1, 'experience' => 100, 'published' => false,
            'goals' => [['description' => 'Найти часы', 'type' => 'findItem', 'count' => 1]]]);
    }

    private function reference(string $kind, string $value, string $variant = 'default'): int
    { return ContentReference::where(compact('kind', 'value', 'variant'))->firstOrFail()->id; }

    public function test_new_ids_are_generated_and_hidden_in_the_actual_form(): void
    {
        $quest = $this->fixtures();
        $this->assertMatchesRegularExpression('/^custom-[a-f0-9]{24}$/', $quest->quest_id);
        $goal = $quest->payload['objectives'][0];
        $this->assertMatchesRegularExpression('/^custom-[a-f0-9]{24}$/', $goal['id']);
        $html = $this->get('/admin/quests/'.$quest->id.'/edit')->assertOk()->getContent();
        $dom = new \DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//input[contains(@name,"[id]") and @type!="hidden"]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="quest[quest_id]" and @type!="hidden"]')->length);
        $this->get('/admin/quests/'.$quest->id.'/fields?section=/objectives/0')->assertOk()->assertSee('data-controller="relation"', false)->assertSee('Локации')->assertSee('Предметы')->assertDontSee($goal['id']);
    }

    public function test_item_and_map_choices_preserve_complex_conditions_and_existing_objects(): void
    {
        $quest = $this->fixtures();$payload = $quest->payload;
        $payload['objectives'][0] += ['zones' => [['id' => 'stable-zone', 'position' => ['x' => 2, 'y' => 3, 'z' => 4]]], 'foundInRaid' => true];
        $payload['objectives'][0]['items'] = [['id' => 'item-watch', 'name' => 'Бронзовые часы', 'customMetadata' => 'сохранить']];
        $payload['objectives'][0]['maps'] = [['id' => '5704e554d2720bac5b8b456e', 'name' => 'Таможня']];
        $quest->payload = $payload;$quest->save();
        $url = '/admin/quests/'.$quest->id.'/fields';
        $this->get($url.'?section=/objectives/0')->assertOk()->assertSee('Бронзовые часы')->assertSee('Таможня')->assertDontSee('customMetadata');
        $fields = [hash('sha256', '/objectives/0/items') => [$this->reference('item', 'item-watch')],
            hash('sha256', '/objectives/0/maps') => [$this->reference('location', '5704e4dad2720bb55b8b4567', 'game')]];
        $this->post($url.'/save?section=/objectives/0', ['revision' => $quest->revision(), 'fields' => $fields])->assertRedirect()->assertSessionHasNoErrors();
        $saved = $quest->fresh()->payload['objectives'][0];
        $this->assertSame($payload['objectives'][0]['items'], $saved['items']);
        $this->assertSame('Маяк', $saved['maps'][0]['name']);
        $this->assertSame('5704e4dad2720bb55b8b4567', $saved['maps'][0]['id']);
        $this->assertSame($payload['objectives'][0]['zones'], $saved['zones']);$this->assertTrue($saved['foundInRaid']);
        $this->assertSame($payload['objectives'][0]['id'], $saved['id']);
    }

    public function test_reward_button_keeps_nested_section_and_saves_a_named_item(): void
    {
        $quest = $this->fixtures();$quest->payload = [...$quest->payload, 'finishRewards' => ['items' => [['item' => 'item-watch', 'count' => 2, 'attributes' => []]]]];$quest->save();
        $html = $this->get('/admin/quests/'.$quest->id.'/fields?section=/finishRewards/items/0')->assertOk()->assertSee('Бронзовые часы')->getContent();
        $dom = new \DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xpath = new \DOMXPath($dom);
        $button = $xpath->query('//button[contains(@formaction,"/save?")]')->item(0);
        $this->assertNotNull($button);$action = $button->getAttribute('formaction');
        $state = $xpath->query('//input[@name="_state"]')->item(0);$this->assertNotNull($state);
        $this->assertStringContainsString('section=%2FfinishRewards%2Fitems%2F0', $action);
        $this->post($action, ['_state' => $state->getAttribute('value'), 'revision' => $quest->revision(), 'fields' => [hash('sha256', '/finishRewards/items/0/item') => $this->reference('item', 'item-key'),
            hash('sha256', '/finishRewards/items/0/count') => '3']])->assertRedirect()->assertSessionHasNoErrors();
        $saved = $quest->fresh()->payload;
        $this->assertSame('item-key', $saved['finishRewards']['items'][0]['item']);$this->assertSame(3, $saved['finishRewards']['items'][0]['count']);
        $this->assertTrue($saved['cmsRewards']);
        $this->assertStringStartsWith('@file:', DB::table('quests')->value('payload'));
        $this->assertStringStartsWith('@file:', DB::table('content_datasets')->value('payload'));
    }

    public function test_grouped_keys_are_kept_separate_and_wrong_kinds_are_rejected(): void
    {
        $this->fixtures();$service = app(ContentFields::class);
        $document = ['objectives' => [['usingWeaponMods' => [['item-watch'], ['item-key']]]]];
        $path = '/objectives/0/usingWeaponMods';$entry = $service->entries($document, $path, 1)[0];
        $this->assertTrue($entry['reference']['multiple']);
        $saved = $service->apply($document, $path, 1, [$entry['key'] => [$this->reference('item', 'item-key')]]);
        $this->assertSame([['item-key'], ['item-key']], $saved['objectives'][0]['usingWeaponMods']);
        $quest = Quest::first();
        $quest->payload = [...$quest->payload, 'finishRewards' => ['items' => [['item' => 'item-watch', 'count' => 1]]]];$quest->save();
        $this->postJson('/admin/quests/'.$quest->id.'/fields/save?section=/finishRewards/items/0', ['revision' => $quest->revision(),
            'fields' => [hash('sha256', '/finishRewards/items/0/item') => $this->reference('location', 'lighthouse', 'slug')]])->assertUnprocessable();
        $this->assertSame('item-watch', $quest->fresh()->payload['finishRewards']['items'][0]['item']);
    }

    public function test_named_presets_add_rewards_and_goals_without_technical_input(): void
    {
        $quest = $this->fixtures();$quest->payload = [...$quest->payload, 'finishRewards' => ['items' => []]];$quest->save();
        $this->get('/admin/quests/'.$quest->id.'/fields?section=/finishRewards/items')->assertOk()->assertSee('Новая запись')->assertDontSee('Имя нового поля');
        $this->post('/admin/quests/'.$quest->id.'/fields/add?section=/finishRewards/items', ['revision' => $quest->revision(), 'addition' => ['preset' => 'record']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['item' => null, 'count' => 1, 'attributes' => []], $quest->fresh()->payload['finishRewards']['items'][0]);
        $this->get('/admin/quests/'.$quest->id.'/fields?section=/finishRewards/items/0')->assertOk()->assertSee('data-controller="relation"', false);
        $goalId = $quest->payload['objectives'][0]['id'];
        $this->post('/admin/quests/'.$quest->id.'/fields/add?section=/objectives', ['revision' => $quest->fresh()->revision(), 'addition' => ['preset' => 'record']])->assertRedirect();
        $goals = $quest->fresh()->payload['objectives'];$this->assertCount(2, $goals);$this->assertNotSame($goalId, $goals[1]['id']);
    }

    public function test_document_point_location_and_document_are_named_and_ids_are_automatic(): void
    {
        $this->fixtures();$dataset = $this->dataset('spawns.json', [['id' => 'stable-point', 'map' => 'lighthouse', 'doc' => 'technical', 'caption' => 'Комната', 'x' => 12.5, 'y' => 54.2]]);
        $this->get('/admin/datasets/'.$dataset->id.'/edit?section=/0')->assertOk()->assertSee('Маяк')->assertSee('Техническая документация')->assertDontSee('stable-point');
        $this->post('/admin/datasets/'.$dataset->id.'/edit/add', ['revision' => $dataset->revision(), 'addition' => ['preset' => 'record']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertMatchesRegularExpression('/^custom-/', $dataset->fresh()->content()[1]['id']);
        $this->assertSame('stable-point', $dataset->fresh()->content()[0]['id']);
    }

    public function test_search_handles_russian_case_english_aliases_limits_and_permissions(): void
    {
        $this->fixtures();
        $params = ['model' => Crypt::encryptString(ReferenceOptions::class), 'name' => Crypt::encryptString('label'), 'key' => Crypt::encryptString('id'),
            'scope' => Crypt::encrypt(['name' => 'picker', 'parameters' => ['item', 'default']]), 'chunk' => 20];
        $url = route('platform.systems.relation');
        $this->postJson($url, [...$params, 'search' => 'БРОНЗОВЫЕ'])->assertOk()->assertJsonPath('0.label', 'Бронзовые часы');
        $this->postJson($url, [...$params, 'search' => 'pocket-watch'])->assertOk()->assertJsonPath('0.label', 'Бронзовые часы');
        $this->postJson($url, [...$params, 'search' => '%'])->assertOk()->assertExactJson([]);
        $this->postJson($url, [...$params, 'search' => 'Маяк'])->assertOk()->assertExactJson([]);
        $user = auth()->user();$user->permissions = ['platform.index' => true];$user->save();
        $this->actingAs($user->fresh())->postJson($url, [...$params, 'search' => 'часы'])->assertForbidden();
    }

    public function test_browser_relation_attributes_work_without_global_empty_string_conversion(): void
    {
        $quest = $this->fixtures();
        $html = $this->get('/admin/quests/'.$quest->id.'/fields?section=/objectives/0')->assertOk()->getContent();
        $dom = new \DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xpath = new \DOMXPath($dom);
        $relation = $xpath->query('//div[@data-controller="relation"]')->item(0);$this->assertNotNull($relation);
        $params = ['search' => 'pocket-watch'];
        // Первая связь — maps, для проверки предмета берём последнюю.
        $relations = $xpath->query('//div[@data-controller="relation"]');$relation = $relations->item($relations->length - 1);
        foreach (['model', 'name', 'key', 'scope', 'append', 'search-columns', 'chunk'] as $key) {
            $params[$key === 'search-columns' ? 'searchColumns' : $key] = $relation->getAttribute('data-relation-'.$key);
        }
        $this->postJson(route('platform.systems.relation'), $params)->assertOk()->assertJsonPath('0.label', 'Бронзовые часы');
    }

    public function test_reindex_is_repeatable_and_updates_names_without_changing_form_values(): void
    {
        $quest = $this->fixtures();$id = $this->reference('item', 'item-watch');
        $this->artisan('wiki:reference-index')->assertSuccessful();$this->artisan('wiki:reference-index')->assertSuccessful();
        $this->assertSame($id, $this->reference('item', 'item-watch'));
        $quest->name = 'Новое название';$quest->save();
        $this->assertSame('Новое название · черновик', ContentReference::where('kind', 'quest')->first()->label);
        $dataset = ContentDataset::where('path', 'guide/items-pve.json')->first();$data = $dataset->content();$data['items'][0]['name'] = 'Карманные часы';$dataset->setContent($data);$dataset->save();
        $this->assertSame('Карманные часы', ContentReference::find($id)->label);
        $this->assertSame(1, ContentReference::where('kind', 'item')->where('value', 'item-watch')->count());
    }

    public function test_large_reference_lists_are_paged_and_allow_named_additions_without_losing_other_pages(): void
    {
        $quest = $this->fixtures();$items = [];
        for ($i = 0; $i < 150; $i++) { $items[] = 'legacy-'.$i; }
        $payload = $quest->payload;$payload['objectives'][0]['items'] = $items;$quest->payload = $payload;$quest->save();
        $service = app(ContentFields::class);$entry = collect($service->entries($payload, '/objectives/0', 1))->firstWhere('label', 'Предметы');
        $this->assertNull($entry['reference']);
        $html = $this->get('/admin/quests/'.$quest->id.'/fields?section=/objectives/0/items&page=2')->assertOk()->getContent();
        $this->assertSame(61, substr_count($html, 'data-controller="relation"'));
        $key = hash('sha256', '/objectives/0/items/60');
        $this->post('/admin/quests/'.$quest->id.'/fields/save?section=/objectives/0/items&page=2', ['revision' => $quest->revision(),
            'fields' => [$key => $this->reference('item', 'item-watch')]])->assertRedirect()->assertSessionHasNoErrors();
        $saved = $quest->fresh()->payload['objectives'][0]['items'];$this->assertCount(150, $saved);
        $this->assertSame('legacy-59', $saved[59]);$this->assertSame('item-watch', $saved[60]);$this->assertSame('legacy-120', $saved[120]);
        $this->post('/admin/quests/'.$quest->id.'/fields/add?section=/objectives/0/items&page=2', ['revision' => $quest->fresh()->revision(),
            'addition' => ['reference' => $this->reference('item', 'item-key')]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('item-key', $quest->fresh()->payload['objectives'][0]['items'][150]);
    }

    public function test_missing_legacy_reference_survives_a_noop_but_cannot_be_added_elsewhere(): void
    {
        $this->fixtures();$links = app(ContentLinks::class);$spec = ['kind' => 'item', 'variant' => 'default', 'multiple' => false];
        $original = ['id' => 'retired-key', 'name' => 'Архивный ключ', 'custom' => true];
        $selected = $links->selected($original, $spec);
        $this->assertSame($original, $links->resolve($selected, $original, $spec, 'item'));
        $this->expectException(\Illuminate\Validation\ValidationException::class);$links->resolve($selected, null, $spec, 'item');
    }

    public function test_point_choices_update_names_and_percentages_keep_fractional_precision(): void
    {
        $this->fixtures();$this->dataset('maps.json', [['id' => 'lighthouse', 'name' => 'Маяк'], ['id' => 'customs', 'name' => 'Таможня']]);
        $dataset = $this->dataset('spawns.json', [['id' => 'point-1', 'map' => 'lighthouse', 'mapName' => 'Маяк', 'doc' => 'technical', 'docName' => 'Техническая документация', 'x' => 0, 'y' => 0]]);
        $this->post('/admin/datasets/'.$dataset->id.'/edit/save?section=/0', ['revision' => $dataset->revision(), 'fields' => [
            hash('sha256', '/0/map') => $this->reference('location', 'customs', 'slug'), hash('sha256', '/0/x') => '12.75']])->assertRedirect()->assertSessionHasNoErrors();
        $point = $dataset->fresh()->content()[0];$this->assertSame('Таможня', $point['mapName']);$this->assertSame(12.75, $point['x']);
        $this->postJson('/admin/datasets/'.$dataset->id.'/edit/save?section=/0', ['revision' => $dataset->fresh()->revision(), 'fields' => [hash('sha256', '/0/y') => '101']])->assertUnprocessable();
        $this->assertSame(0, $dataset->fresh()->content()[0]['y']);
    }

    public function test_rendered_multiple_choices_preserve_the_original_order_on_noop_save(): void
    {
        $quest = $this->fixtures();$payload = $quest->payload;
        // Порядок связей намеренно противоположен порядку PK поискового индекса.
        $payload['objectives'][0]['maps'] = ['5704e4dad2720bb55b8b4567', '5704e554d2720bac5b8b456e'];
        $quest->payload = $payload;$quest->save();$key = hash('sha256', '/objectives/0/maps');
        $html = $this->get('/admin/quests/'.$quest->id.'/fields?section=/objectives/0')->assertOk()->getContent();
        $dom = new \DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xpath = new \DOMXPath($dom);
        $options = $xpath->query('//select[@name="fields['.$key.'][]"]/option[@selected]');$selected = [];
        foreach ($options as $option) { $selected[] = $option->getAttribute('value'); }
        $this->assertSame([(string) $this->reference('location', $payload['objectives'][0]['maps'][0], 'game'),
            (string) $this->reference('location', $payload['objectives'][0]['maps'][1], 'game')], $selected);
        $state = $xpath->query('//input[@name="_state"]')->item(0)->getAttribute('value');
        $this->post('/admin/quests/'.$quest->id.'/fields/save?section=/objectives/0', ['revision' => $quest->revision(), '_state' => $state,
            'fields' => [$key => $selected]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($payload['objectives'], $quest->fresh()->payload['objectives']);
    }
}
