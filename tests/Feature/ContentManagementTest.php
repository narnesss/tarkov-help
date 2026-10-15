<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\{ContentDataset, Quest, User};
use App\Services\QuestEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Проверяет админку, публичные данные и сохранение ID прогресса. */
final class ContentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function editor(bool $allowed = true): User
    {
        return User::create(['name' => 'Редактор', 'email' => 'editor@example.test',
            'password' => bcrypt('test-password'), 'permissions' => ['platform.index' => true, 'wiki.content' => $allowed]]);
    }

    private function form(string $name = 'Новый квест'): array
    {
        if (!ContentDataset::where('path', 'guide/quests-regular.json')->exists()) {
            $this->dataset('guide/quests-regular.json', ['traders' => [['id' => 'prapor', 'name' => 'Прапор'], ['id' => 'therapist', 'name' => 'Терапевт']]]);
        }
        return ['quest_id' => 'custom-quest', 'mode' => 'pve', 'name' => $name, 'trader_id' => 'prapor',
            'min_player_level' => 2, 'experience' => 100, 'description' => 'Описание',
            'published' => true, 'goals' => [['id' => 'goal-1', 'description' => 'Найти предмет', 'type' => 'findItem']]];
    }

    private function dataset(string $path, array $data): ContentDataset
    {
        $dataset = new ContentDataset(['path' => $path]);$dataset->setContent($data);$dataset->save();return $dataset;
    }

    public function test_admin_login_and_permissions_are_isolated_from_players(): void
    {
        $this->get('/admin/quests')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->editor(false))->get('/admin/quests')->assertForbidden();
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_admin_login_persists_without_remember_cookie(): void
    {
        $editor = $this->editor();
        $login = $this->post('/admin/login', ['email' => $editor->email, 'password' => 'test-password', 'remember' => false])
            ->assertRedirect('/admin/quests');
        $sessionId = $login->getCookie('wiki_admin_session')->getValue();
        $session = DB::table('admin_sessions')->where('id', $sessionId)->first();
        $this->assertNotNull($session);
        $payload = json_decode(base64_decode($session->payload), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($editor->id, $payload[auth()->guard()->getName()] ?? null);
        $this->app['auth']->forgetGuards();
        $this->withCookie('wiki_admin_session', $sessionId)->get('/admin/quests')->assertOk()->assertSee('Квесты');
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_orchid_forms_create_and_edit_quests_and_public_api_reflects_edits(): void
    {
        $this->dataset('guide/quests-pve.json', ['meta' => ['mode' => 'pve']]);
        $this->dataset('home.json', ['modes' => []]);
        $this->actingAs($this->editor())->get('/admin/quests/create')->assertOk()->assertSee('Новый квест');
        $this->post('/admin/quests/create/save', ['quest' => $this->form()])->assertRedirect();
        $quest = Quest::firstOrFail();
        $this->get('/admin/quests/'.$quest->id.'/edit')->assertOk()->assertSee('goal-1');
        $input = $this->form('Исправленное название');unset($input['mode']);
        $input['revision'] = $quest->revision();
        $this->post('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertRedirect();
        $this->getJson('/api/content/data/guide/quests-pve.json')->assertOk()
            ->assertJsonPath('cms', true)->assertJsonPath('tasks.0.name', 'Исправленное название')
            ->assertJsonPath('tasks.0.objectives.0.id', 'goal-1');
        $this->getJson('/api/content/data/home.json')->assertJsonPath('modes.pve.tasks.0.name', 'Исправленное название')
            ->assertJsonPath('modes.pve.total', 1);
        $input['published'] = false;
        $input['revision'] = $quest->fresh()->revision();
        $this->post('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertRedirect();
        $this->getJson('/api/content/data/guide/quests-pve.json')->assertJsonCount(0, 'tasks');
        $this->getJson('/api/content/quest/custom-quest')->assertJsonPath('exists', false);
        $this->getJson('/api/content/data/home.json')->assertJsonPath('modes.pve.total', 0);
    }

    public function test_an_editor_without_content_permission_cannot_save(): void
    {
        $this->actingAs($this->editor(false))->post('/admin/quests/create/save', ['quest' => $this->form()])->assertForbidden();
        $this->assertSame(0, Quest::count());
    }

    public function test_goal_rows_preserve_complex_conditions_and_allow_additions(): void
    {
        $quest = app(QuestEditor::class)->save(new Quest, $this->form());
        $quest->payload = [...$quest->payload, 'objectives' => [['id' => 'goal-1', 'description' => 'Найти предмет',
            'type' => 'findItem', 'foundInRaid' => true, 'item' => ['id' => 'item-1']]]];$quest->save();
        $input = $this->form();$input['revision'] = $quest->revision();
        $input['goals'] = [7 => ['id' => 'goal-2', 'description' => 'Выйти с локации', 'type' => 'extract'],
            2 => ['id' => 'goal-1', 'description' => 'Правка текста', 'type' => 'findItem', 'count' => '3']];
        $this->actingAs($this->editor())->post('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertRedirect();
        $payload = $quest->fresh()->payload;
        $this->assertSame(3, $payload['objectives'][0]['count']);
        $this->assertSame('item-1', $payload['objectives'][0]['item']['id']);
        $this->assertTrue($payload['objectives'][0]['foundInRaid']);
        $this->assertCount(2, $payload['objectives']);
        $input['revision'] = $quest->fresh()->revision();$input['goals'][2]['count'] = '';
        $this->post('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertRedirect();
        $this->assertArrayNotHasKey('count', $quest->fresh()->payload['objectives'][0]);
    }

    public function test_admin_mutations_require_csrf_outside_test_mode(): void
    {
        $this->actingAs($this->editor());
        $this->app->instance('env', 'production');
        $this->postJson('/admin/quests/create/save', ['quest' => $this->form()])->assertStatus(419);
        $this->assertSame(0, Quest::count());
    }

    public function test_existing_quest_and_objective_ids_cannot_be_changed(): void
    {
        $quest = app(QuestEditor::class)->save(new Quest, $this->form());
        $this->actingAs($this->editor());
        $input = $this->form();$input['goals'][0]['id'] = 'changed';
        $this->postJson('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertUnprocessable()->assertJsonValidationErrors('quest.goals');
        $input = $this->form();$input['quest_id'] = 'changed';
        $this->postJson('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertUnprocessable()->assertJsonValidationErrors('quest.quest_id');
        $this->assertSame('goal-1', $quest->fresh()->payload['objectives'][0]['id']);
    }

    public function test_datasets_are_editable_and_proxy_secret_is_required_for_public_reads(): void
    {
        $dataset = $this->dataset('maps.json', ['maps' => [['id' => 'customs', 'name' => 'Таможня', 'enabled' => true, 'level' => 1]]]);
        $this->actingAs($this->editor())->get('/admin/datasets')->assertOk();
        $this->get('/admin/datasets/'.$dataset->id.'/edit')->assertOk();
        $this->post('/admin/datasets/'.$dataset->id.'/edit/save?section=/maps/0', [
            'revision' => $dataset->revision(), 'fields' => [hash('sha256', '/maps/0/name') => 'Таможня новая',
                hash('sha256', '/maps/0/enabled') => '0', hash('sha256', '/maps/0/level') => '3']])->assertRedirect()->assertSessionHasNoErrors();
        config(['api.proxy_required' => true, 'api.proxy_token' => str_repeat('t', 64)]);
        $this->getJson('/api/content/data/maps.json')->assertForbidden();
        $reply = $this->getJson('/api/content/data/maps.json', ['X-Wiki-Proxy-Token' => str_repeat('t', 64), 'X-Wiki-Client-IP' => '198.51.100.1'])
            ->assertOk()->assertJsonPath('maps.0.id', 'customs');
        $reply->assertJsonPath('maps.0.name', 'Таможня новая')->assertJsonPath('maps.0.enabled', false)->assertJsonPath('maps.0.level', 3);
        $this->assertTrue($reply->headers->hasCacheControlDirective('no-store'));
        $this->postJson('/api/content/data/maps.json')->assertStatus(405);
    }

    public function test_names_trader_selection_and_nested_quest_fields_need_no_json(): void
    {
        $quest = app(QuestEditor::class)->save(new Quest, $this->form());
        $quest->payload = [...$quest->payload, 'finishRewards' => ['items' => [['id' => 'reward-1', 'count' => 2, 'name' => 'Награда']]]];$quest->save();
        $this->actingAs($this->editor());
        $this->get('/admin/quests')->assertOk()->assertSee('Прапор');
        $this->get('/admin/quests/'.$quest->id.'/edit')->assertOk()->assertSee('Терапевт')->assertDontSee('Условия (JSON)')->assertDontSee('Награды (JSON)');
        $this->get('/admin/quests/'.$quest->id.'/fields?section=/finishRewards/items/0')->assertOk()->assertSee('Награда');
        $this->post('/admin/quests/'.$quest->id.'/fields/save?section=/finishRewards/items/0', [
            'revision' => $quest->revision(), 'fields' => [hash('sha256', '/finishRewards/items/0/count') => '5']])->assertRedirect();
        $this->assertSame(5, $quest->fresh()->payload['finishRewards']['items'][0]['count']);
        $this->assertTrue($quest->fresh()->payload['cmsRewards']);
        $input = $this->form();$input['trader_id'] = 'therapist';$input['revision'] = $quest->fresh()->revision();
        $this->post('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertRedirect();
        $this->assertSame('Терапевт', $quest->fresh()->payload['trader']['name']);
        $this->assertStringStartsWith('@file:', DB::table('quests')->value('payload'));
        $this->assertStringStartsWith('@file:', DB::table('content_datasets')->value('payload'));
    }

    public function test_stale_form_and_unknown_trader_cannot_overwrite_content(): void
    {
        $quest = app(QuestEditor::class)->save(new Quest, $this->form());$original = $quest->revision();
        $input = $this->form('Свежая правка');$input['revision'] = $original;
        app(QuestEditor::class)->save($quest, $input);
        $this->actingAs($this->editor());$input['name'] = 'Устаревшая правка';
        $this->postJson('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertUnprocessable()->assertJsonValidationErrors('quest.revision');
        $input['trader_id'] = 'unknown';
        $this->postJson('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertUnprocessable()->assertJsonValidationErrors('quest.trader_id');
        $this->assertSame('Свежая правка', $quest->fresh()->name);
    }

    public function test_legacy_content_moves_to_files_without_losing_edits_or_timestamps(): void
    {
        $task = ['id' => 'legacy', 'name' => 'Правка редактора', 'objectives' => [['id' => 'stable', 'description' => 'Текст', 'count' => 4]]];
        $id = DB::table('quests')->insertGetId(['quest_id' => 'legacy', 'mode' => 'pve', 'name' => 'Правка редактора',
            'published' => false, 'payload' => json_encode($task), 'updated_at' => '2026-10-01 00:00:00']);
        DB::table('content_datasets')->insert(['path' => 'maps.json', 'payload' => base64_encode(gzencode('{"maps":[{"id":"stable"}]}'))]);
        $this->artisan('wiki:content-files', ['--move' => true])->assertSuccessful();
        $this->artisan('wiki:content-files', ['--move' => true])->assertSuccessful();
        $quest = Quest::findOrFail($id);
        $this->assertSame($task, $quest->payload);$this->assertFalse($quest->published);
        $this->assertSame('2026-10-01 00:00:00', $quest->getRawOriginal('updated_at'));
        $this->assertSame('stable', ContentDataset::first()->content()['maps'][0]['id']);
        $this->assertSame(0, DB::table('quests')->where('payload', 'not like', '@file:%')->count());
        $this->assertSame(0, DB::table('content_datasets')->where('payload', 'not like', '@file:%')->count());
    }

    public function test_seed_import_is_repeatable_without_overwriting_edits(): void
    {
        $directory = sys_get_temp_dir().'/wiki-content-'.bin2hex(random_bytes(6));mkdir($directory);
        try {
            mkdir($directory.'/datasets');
            file_put_contents($directory.'/datasets.json', '["maps.json"]');
            file_put_contents($directory.'/datasets/maps.json.gz', gzencode('{"maps":[]}'));
            file_put_contents($directory.'/quests.jsonl.gz', gzencode(json_encode(['mode' => 'pve', 'task' => [
                'id' => 'custom-quest', 'name' => 'Импорт', 'objectives' => [], 'trader' => ['id' => 'prapor'],
            ]])."\n"));
            $this->artisan('wiki:import-content', ['--source' => $directory])->assertSuccessful();
            Quest::first()->update(['name' => 'Правка', 'published' => false]);
            $dataset = ContentDataset::first();$dataset->setContent(['edited' => true]);$dataset->save();
            $this->artisan('wiki:import-content', ['--source' => $directory])->assertSuccessful();
            $this->assertSame('Правка', Quest::first()->name);
            $this->assertFalse(Quest::first()->published);
            $this->assertTrue(ContentDataset::first()->content()['edited']);
            $this->assertSame(1, Quest::count());
        } finally { unlink($directory.'/datasets/maps.json.gz');rmdir($directory.'/datasets');unlink($directory.'/datasets.json');unlink($directory.'/quests.jsonl.gz');rmdir($directory); }
    }
}
