<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\ContentDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Проверяет HTTP-контракт живого HTML и снятие страниц сущностей с публикации. */
final class SeoEntitiesHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    { parent::setUp(); config(['site.url'=>'https://wiki.test', 'api.proxy_required'=>false]); }

    private function dataset(string $path, array $content): ContentDataset
    {
        return ContentDataset::withoutEvents(fn () => ContentDataset::updateOrCreate(['path'=>$path],
            ['payload'=>base64_encode(gzencode(json_encode($content, JSON_THROW_ON_ERROR)))]));
    }

    private function url(string $path, array $query = []): string
    { return '/api/content/seo?'.http_build_query(['path'=>$path, ...$query]); }

    private function document(string $html): \DOMXPath
    {
        $dom = new \DOMDocument(); $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">'.$html); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return new \DOMXPath($dom);
    }

    public function test_live_entity_names_content_and_archive_status_replace_existing_static_pages(): void
    {
        foreach ([
            ['/reference/items/5447a9cd4bdc2dbd208b4567', 'guide/items-pve.json', 'items', '5447a9cd4bdc2dbd208b4567'],
            ['/boss/reshala', 'guide/bosses.json', 'profiles', 'reshala'],
            ['/raid/shoreline', 'raid/maps.json', null, 'shoreline'],
            ['/documents/shoreline', 'maps.json', null, 'shoreline'],
        ] as [$path, $dataset, $collection, $id]) {
            $name = 'Живое название <img src=x onerror=alert(1)> & «0»';
            $row = ['id'=>$id, 'slug'=>$id, 'name'=>$name, 'description'=>'Описание с буквальным <script>alert(1)</script>', 'weight'=>0];
            $wrap = static fn (array $row): array => $collection === null ? [$row] : [$collection=>[$row]];
            $this->dataset($dataset, $wrap($row));
            $response = $this->getJson($this->url($path))->assertOk()->assertJsonPath('seoVersion', 1)
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Set-Cookie');
            $html = $response->json('html'); $xpath = $this->document($html);
            $this->assertSame($name, $xpath->query('//h1[@id="entity-seo-title"]')->item(0)->textContent);
            $this->assertStringContainsString($name, $xpath->query('//title')->item(0)->textContent);
            $this->assertSame(0, $xpath->query('//img[@onerror]')->length);
            $this->assertSame('https://wiki.test'.$path, $xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'));
            $row['name'] = 'Исправленное название'; $this->dataset($dataset, $wrap($row));
            $this->assertStringContainsString('Исправленное название', $this->getJson($this->url($path))->assertOk()->json('html'));
            $row['cmsArchived'] = true; $this->dataset($dataset, $wrap($row));
            $this->getJson($this->url($path))->assertNotFound()->assertJsonPath('status', 404);
            $this->assertDatabaseCount('user_state', 0);
        }
    }

    public function test_new_items_modes_pagination_and_legacy_urls_have_consistent_canonical(): void
    {
        $rows = [];
        for ($i = 0; $i < 101; $i++) { $rows[] = ['id'=>'seo-item-'.$i, 'name'=>'Предмет '.$i]; }
        $this->dataset('guide/items-regular.json', ['items'=>$rows]);
        $this->getJson($this->url('/reference/items/seo-item-0'))->assertNotFound();
        foreach (['/reference/items/seo-item-0', '/reference.html'] as $path) {
            $html = $this->getJson($this->url($path, ['item'=>'seo-item-0', 'mode'=>'regular']))->assertOk()->json('html');
            $this->assertSame('https://wiki.test/reference/items/seo-item-0?mode=regular', $this->document($html)->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'));
        }
        $html = $this->getJson($this->url('/reference/items', ['mode'=>'regular', 'page'=>2]))->assertOk()->json('html');
        $xpath = $this->document($html);
        $this->assertSame('https://wiki.test/reference/items?mode=regular&page=2', $xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'));
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Связанные страницы"]//a')->length);
        $this->assertSame(0, $xpath->query('//script[contains(@src,"bootstrap.mjs")]')->length);
        $this->getJson($this->url('/reference/items', ['mode'=>'regular', 'page'=>3]))->assertNotFound();
        foreach (['item', 'boss', 'map', 'tab'] as $key) { $this->getJson($this->url('/reference', [$key=>['invalid']]))->assertUnprocessable(); }
    }
}
