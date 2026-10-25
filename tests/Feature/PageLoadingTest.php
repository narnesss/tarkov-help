<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\PageViews;
use Tests\TestCase;

final class PageLoadingTest extends TestCase
{
    public function test_every_page_has_accessible_loading_and_document_pages_can_finish_without_bootstrap(): void
    {
        foreach (array_keys(config('pages')) as $page) {
            $html = app(PageViews::class)->render($page, 'static');
            $dom = new \DOMDocument(); $previous = libxml_use_internal_errors(true);
            try { $dom->loadHTML('<?xml encoding="UTF-8">'.$html); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//*[@id="page-boot-status" and @role="status" and @aria-live="polite"]')->length, $page);
            $this->assertSame(1, $xpath->query('//*[@id="page-boot-status"]/*[@aria-hidden="true"]')->length, $page);
            $boot = $xpath->query('//body')->item(0)->getAttribute('data-page-boot');
            $hasModule = $xpath->query('//script[@type="module" and (contains(@src,"bootstrap.mjs") or contains(@src,"quest-map-source.mjs"))]')->length > 0;
            $this->assertSame($hasModule ? 'interactive' : 'document', $boot, $page);
            $this->assertStringContainsString('prefers-reduced-motion: reduce', $html);
            $this->assertStringContainsString("document.body.dataset.pageBoot === 'document'", $html);
        }
    }
}
