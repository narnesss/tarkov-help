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
        return ['quest_id' => 'custom-quest', 'mode' => 'pve', 'name' => $name, 'trader_id' => 'prapor',
            'trader_name' => 'Прапор', 'min_player_level' => 2, 'experience' => 100, 'description' => 'Описание',
            'published' => true, 'details' => '{}', 'objectives' => '[{"id":"goal-1","description":"Найти предмет","type":"findItem"}]', 'rewards' => '{}'];
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

    public function test_orchid_forms_create_and_edit_quests_and_public_api_reflects_edits(): void
    {
        $this->dataset('guide/quests-pve.json', ['meta' => ['mode' => 'pve']]);
        $this->dataset('home.json', ['modes' => []]);
        $this->actingAs($this->editor())->get('/admin/quests/create')->assertOk()->assertSee('Новый квест');
        $this->post('/admin/quests/create/save', ['quest' => $this->form()])->assertRedirect();
        $quest = Quest::firstOrFail();
        $this->get('/admin/quests/'.$quest->id.'/edit')->assertOk()->assertSee('goal-1');
        $input = $this->form('Исправленное название');unset($input['mode']);
        $this->post('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertRedirect();
        $this->getJson('/api/content/data/guide/quests-pve.json')->assertOk()
            ->assertJsonPath('cms', true)->assertJsonPath('tasks.0.name', 'Исправленное название')
            ->assertJsonPath('tasks.0.objectives.0.id', 'goal-1');
        $this->getJson('/api/content/data/home.json')->assertJsonPath('modes.pve.tasks.0.name', 'Исправленное название')
            ->assertJsonPath('modes.pve.total', 1);
        $input['published'] = false;
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
        $input = $this->form();unset($input['objectives']);
        $input['goals'] = [['id' => 'goal-1', 'description' => 'Правка текста',
            'conditions' => '{"type":"findItem","count":3,"foundInRaid":true,"item":{"id":"item-1"}}'],
            ['id' => 'goal-2', 'description' => 'Выйти с локации', 'conditions' => '{"type":"extract"}']];
        $this->actingAs($this->editor())->post('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertRedirect();
        $payload = $quest->fresh()->payload;
        $this->assertSame(3, $payload['objectives'][0]['count']);
        $this->assertSame('item-1', $payload['objectives'][0]['item']['id']);
        $this->assertCount(2, $payload['objectives']);
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
        $input = $this->form();$input['objectives'] = '[{"id":"changed","description":"Текст"}]';
        $this->postJson('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertUnprocessable()->assertJsonValidationErrors('quest.objectives');
        $input = $this->form();$input['quest_id'] = 'changed';
        $this->postJson('/admin/quests/'.$quest->id.'/edit/save', ['quest' => $input])->assertUnprocessable()->assertJsonValidationErrors('quest.quest_id');
        $this->assertSame('goal-1', $quest->fresh()->payload['objectives'][0]['id']);
    }

    public function test_datasets_are_editable_and_proxy_secret_is_required_for_public_reads(): void
    {
        $dataset = $this->dataset('maps.json', ['maps' => []]);
        $this->actingAs($this->editor())->get('/admin/datasets')->assertOk();
        $this->get('/admin/datasets/'.$dataset->id.'/edit')->assertOk();
        $this->post('/admin/datasets/'.$dataset->id.'/edit/save', ['content' => '{"maps":[{"id":"customs"}]}'])->assertRedirect();
        config(['api.proxy_required' => true, 'api.proxy_token' => str_repeat('t', 64)]);
        $this->getJson('/api/content/data/maps.json')->assertForbidden();
        $reply = $this->getJson('/api/content/data/maps.json', ['X-Wiki-Proxy-Token' => str_repeat('t', 64), 'X-Wiki-Client-IP' => '198.51.100.1'])
            ->assertOk()->assertJsonPath('maps.0.id', 'customs');
        $this->assertTrue($reply->headers->hasCacheControlDirective('no-store'));
        $this->postJson('/api/content/data/maps.json')->assertStatus(405);
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
