<?php

namespace EduLazaro\Larascraper\Tests;

use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Tests\Support\ArrayPartsCrawler;
use EduLazaro\Larascraper\Tests\Support\TestScraper;
use EduLazaro\Larascraper\Tests\Support\TitleCrawler;
use EduLazaro\Larascraper\Tests\Support\XmlItemsCrawler;
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

    public function test_a_crawler_can_be_created_and_parsed_standalone(): void
    {
        // No fetch: the Crawler parses HTML you already have, via the create() factory.
        $data = TitleCrawler::create(self::HTML)->parse();

        $this->assertSame(['title' => 'Bike shop'], $data);
    }

    public function test_run_static_entry_returns_the_same_data_as_legacy_create_parse(): void
    {
        // The new standard entry (Class::run) mirrors Scraper::run()/Spider::run().
        $viaRun = TitleCrawler::run(self::HTML);
        $viaLegacy = TitleCrawler::create(self::HTML)->parse();

        $this->assertSame(['title' => 'Bike shop'], $viaRun);
        $this->assertSame($viaLegacy, $viaRun);
    }

    public function test_filter_xml_selects_nodes_from_an_xml_string(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <feed>
                <item><title>First</title></item>
                <item><title>Second</title></item>
                <item><title>Third</title></item>
            </feed>
            XML;

        $this->assertSame(['First', 'Second', 'Third'], XmlItemsCrawler::run($xml));
    }

    public function test_raw_returns_the_exact_string_input(): void
    {
        $crawler = new class(self::HTML) extends \EduLazaro\Larascraper\Crawler {
            protected function handle(): mixed
            {
                return $this->publicRaw();
            }

            public function publicRaw(): mixed
            {
                return $this->raw();
            }
        };

        $this->assertSame(self::HTML, $crawler->publicRaw());
    }

    public function test_raw_returns_the_exact_array_input_for_multi_part_documents(): void
    {
        $parts = ['a' => '<x/>', 'b' => '<y/>'];

        // The array flows through raw() untouched, and named parts are readable.
        $this->assertSame(['a' => '<x/>', 'b' => '<y/>'], ArrayPartsCrawler::run($parts));
    }

    public function test_filter_on_a_non_string_input_throws_a_clear_exception(): void
    {
        $crawler = new class(['a' => '<x/>']) extends \EduLazaro\Larascraper\Crawler {
            protected function handle(): mixed
            {
                return $this->filter('a');
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('filter() needs a string document');

        $crawler->parse();
    }

    public function test_html_on_a_non_string_input_throws_a_clear_exception(): void
    {
        $crawler = new class(['a' => '<x/>']) extends \EduLazaro\Larascraper\Crawler {
            protected function handle(): mixed
            {
                return $this->html();
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('html() needs a string document');

        $crawler->parse();
    }

    public function test_filter_xml_caches_the_xml_dom_crawler_across_calls(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <feed>
                <item><title>First</title></item>
                <item><title>Second</title></item>
            </feed>
            XML;

        $crawler = new class($xml) extends \EduLazaro\Larascraper\Crawler {
            protected function handle(): mixed
            {
                return null;
            }

            public function filterXml(string $selector): void
            {
                $this->filter($selector, 'xml');
            }

            public function xmlDom(): mixed
            {
                return $this->xmlDom;
            }
        };

        // Nothing built until the first xml query.
        $this->assertNull($crawler->xmlDom());

        $crawler->filterXml('item');
        $built = $crawler->xmlDom();
        $this->assertNotNull($built);

        // A second xml query must reuse the exact same DomCrawler instance:
        // addXmlContent runs once and the xml document is not rebuilt per call.
        $crawler->filterXml('title');
        $this->assertSame($built, $crawler->xmlDom());
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
