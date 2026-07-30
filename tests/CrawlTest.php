<?php

namespace EduLazaro\Larascraper\Tests;

use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Tests\Support\TestScraper;
use EduLazaro\Larascraper\Tests\Support\TitleCrawler;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * Exercises the two crawl modes exposed by FetchBuilder::crawl():
 *
 *   - Class mode: crawl(SomeCrawler::class)->run() fetches the page, hands the
 *     HTML to the Crawler, and wraps the parsed data in a ScraperResponse.
 *   - Selector mode: crawl('css selector')->text()/->texts() fetches the page
 *     and pulls text out inline via a Symfony DomCrawler, with no Crawler class.
 *
 * All cases run against the `http` driver via Http::fake(), so no headless
 * browser is required.
 */
class CrawlTest extends BaseTestCase
{
    /**
     * A page with a <title> and a repeated .bike-card > h3 structure.
     */
    private const HTML = <<<'HTML'
        <html>
            <head><title>Bike shop</title></head>
            <body>
                <div class="bike-card"><h3>Roadster</h3></div>
                <div class="bike-card"><h3>Mountain</h3></div>
                <div class="bike-card"><h3>Cruiser</h3></div>
            </body>
        </html>
        HTML;

    /**
     * Register the fake HTML response for every request.
     */
    protected function fakeHtml(): void
    {
        Http::fake([
            '*' => Http::response(self::HTML, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]),
        ]);
    }

    public function test_class_mode_crawl_returns_the_parsed_data_in_a_scraper_response(): void
    {
        $this->fakeHtml();

        $response = TestScraper::make()
            ->scrape('https://shop.test/bikes')
            ->crawl(TitleCrawler::class)
            ->run();

        $this->assertInstanceOf(ScraperResponse::class, $response);
        $this->assertTrue($response->success);
        $this->assertSame(['title' => 'Bike shop'], $response->data);
    }

    public function test_selector_mode_text_returns_the_first_match(): void
    {
        $this->fakeHtml();

        $text = TestScraper::make()
            ->scrape('https://shop.test/bikes')
            ->driver('http')
            ->crawl('.bike-card h3')
            ->text();

        $this->assertSame('Roadster', $text);
    }

    public function test_selector_mode_texts_returns_every_match(): void
    {
        $this->fakeHtml();

        $texts = TestScraper::make()
            ->scrape('https://shop.test/bikes')
            ->crawl('.bike-card h3')
            ->texts();

        $this->assertSame(['Roadster', 'Mountain', 'Cruiser'], $texts);
    }

    public function test_selector_mode_returns_empty_results_without_throwing(): void
    {
        $this->fakeHtml();

        $builder = TestScraper::make()->scrape('https://shop.test/bikes');

        $this->assertSame('', $builder->crawl('.missing')->text());

        // A fresh builder (fetch is memoized per builder) for the list variant.
        $texts = TestScraper::make()
            ->scrape('https://shop.test/bikes')
            ->crawl('.missing')
            ->texts();

        $this->assertSame([], $texts);
    }

    public function test_selector_mode_run_is_rejected(): void
    {
        $this->fakeHtml();

        $this->expectException(LogicException::class);

        // run() is only valid in class mode; a bare CSS selector must use
        // text()/texts() instead.
        TestScraper::make()
            ->scrape('https://shop.test/bikes')
            ->crawl('.bike-card h3')
            ->run();
    }
}
