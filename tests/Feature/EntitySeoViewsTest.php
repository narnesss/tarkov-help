<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\{PageSeo, PageViews};
use Tests\TestCase;

/** Проверяет безопасный читаемый HTML сущностей до загрузки интерактивных модулей. */
final class EntitySeoViewsTest extends TestCase
{
    private function document(string $html): \DOMXPath
    {
        $dom = new \DOMDocument(); $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">'.$html); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return new \DOMXPath($dom);
    }

    private function content(): array
    {
        return ['entity'=>[
            'name'=>'Сущность <img src=x onerror=alert(1)> & "кавычки"',
            'description'=>'Описание с буквальным <script>alert(2)</script>',
            'facts'=>['Ноль: 0', 'Название «предмета»'],
            'links'=>[['name'=>'Связанный раздел', 'url'=>'/reference/items'], ['name'=>'Опасный адрес', 'url'=>'javascript:alert(3)']],
        ]];
    }

    public function test_entity_content_survives_outside_client_containers_for_every_family(): void
    {
        config(['site.url'=>'https://wiki.test']);
        foreach (['reference'=>['item'=>'seo-fixture'], 'boss'=>['boss'=>'seo-fixture'], 'raid'=>['map'=>'customs'], 'documents'=>['map'=>'customs']] as $page=>$params) {
            $html = app(PageViews::class)->render($page, 'php', 'Живое название', $params, $this->content());
            $xpath = $this->document($html);
            $this->assertSame(1, $xpath->query('//body[@data-server-entity="true"]')->length, $page);
            $this->assertSame(1, $xpath->query('//body/section[@data-seo-fallback]')->length, $page);
            $this->assertSame($this->content()['entity']['name'], $xpath->query('//section[@data-seo-fallback]/h1')->item(0)->textContent);
            $this->assertSame(0, $xpath->query('//section[@data-seo-fallback]//img | //section[@data-seo-fallback]//script')->length);
            $this->assertSame(1, $xpath->query('//section[@data-seo-fallback]//a[@href="/reference/items"]')->length);
            $this->assertSame(0, $xpath->query('//a[starts-with(@href,"javascript:")]')->length);
            $this->assertStringContainsString('Ноль: 0', $xpath->query('//section[@data-seo-fallback]')->item(0)->textContent);
            $this->assertSame('interactive', $xpath->query('//body')->item(0)->getAttribute('data-page-boot'));
        }
    }

    public function test_server_only_directory_has_working_links_and_no_bootstrap_controls(): void
    {
        $page = 'reference'; $params = ['tab'=>'items', 'page'=>2]; $runtime = 'php';
        $meta = config('pages.reference'); $seo = app(PageSeo::class)->metadata($page, $params); $title = $seo['title'];
        $serverContent = $this->content() + ['number'=>2, 'pages'=>3, 'previousUrl'=>'/reference/items', 'nextUrl'=>'/reference/items?page=3'];
        $html = view('pages.entity-directory', compact('page', 'params', 'runtime', 'meta', 'seo', 'title', 'serverContent'))->render();
        $xpath = $this->document($html);
        $this->assertSame(0, $xpath->query('//header//button | //header//select')->length);
        $this->assertSame(1, $xpath->query('//header//a[@href="/account"]')->length);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Страницы каталога"]//a[@href="/reference/items?page=3"]')->length);
        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertSame(0, $xpath->query('//script[contains(@src,"bootstrap.mjs")]')->length);
        $this->assertSame('document', $xpath->query('//body')->item(0)->getAttribute('data-page-boot'));
    }
}
