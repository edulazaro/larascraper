<?php

namespace EduLazaro\Larascraper\Tests;

use EduLazaro\Larascraper\Exceptions\RequestException;
use EduLazaro\Larascraper\Exceptions\ScrapeException;
use EduLazaro\Larascraper\Support\RequestResponse;
use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Tests\Support\FailingCrawler;
use EduLazaro\Larascraper\Tests\Support\TestScraper;
use EduLazaro\Larascraper\Tests\Support\ThrowingScraper;
use Illuminate\Support\Facades\Http;

/**
 * Covers the two-layer failure model:
 *
 *  - Request-level failure: fetch() throws RequestException carrying the
 *    RequestResponse (branch on ->response->status).
 *  - Scrape-level failure inside a Crawler: caught by crawl()->run() and folded
 *    into a ScraperResponse(success = false) (NOT thrown).
 *  - Scrape-level failure raised by the scraper's OWN handle(): caught by run()
 *    and folded into a failed ScraperResponse (like $this->fail()).
 *
 * Everything is driven by Http::fake().
 */
class ExceptionsTest extends BaseTestCase
{
    /**
     * A status the fetcher treats as failure, surviving the (single) retry,
     * throws RequestException carrying the RequestResponse.
     */
    public function test_a_failed_request_throws_request_exception_with_the_response(): void
    {
        Http::fake([
            '*' => Http::response('boom', 500),
        ]);

        try {
            TestScraper::run('https://shop.test/bikes/4');
            $this->fail('Expected a RequestException to be thrown.');
        } catch (RequestException $e) {
            $this->assertInstanceOf(RequestResponse::class, $e->response);
            $this->assertSame(500, $e->response->status);
        }
    }

    /**
     * A ScrapeException thrown INSIDE a crawler is caught by the crawl terminal
     * and folded into a failed ScraperResponse; it is not re-thrown, and the
     * underlying request still reports a 200.
     */
    public function test_a_crawler_scrape_exception_is_captured_into_the_response(): void
    {
        Http::fake([
            '*' => Http::response('<html><head><title>Bike 4</title></head></html>', 200),
        ]);

        $scraper = TestScraper::make();
        $response = $scraper
            ->scrape('https://shop.test/bikes/4')
            ->crawl(FailingCrawler::class)
            ->run();

        $this->assertInstanceOf(ScraperResponse::class, $response);
        $this->assertFalse($response->success);
        $this->assertSame('captcha', $response->error);
        $this->assertNull($response->data);

        // The underlying request still reports 200; those wire facts live on the
        // scraper instance ($this->request), not on the response.
        $this->assertInstanceOf(RequestResponse::class, $scraper->request);
        $this->assertSame(200, $scraper->request->status);
    }

    /**
     * A ScrapeException raised by the scraper's OWN handle() is caught by run()
     * (handleToResponse) and folded into a failed ScraperResponse, exactly like
     * $this->fail() — the caller sees success = false, not an exception.
     */
    public function test_a_scraper_scrape_exception_becomes_a_failed_response(): void
    {
        Http::fake([
            '*' => Http::response('<html><head><title>Bike 4</title></head></html>', 200),
        ]);

        $response = ThrowingScraper::run('https://shop.test/bikes/4');

        $this->assertInstanceOf(ScraperResponse::class, $response);
        $this->assertFalse($response->success);
        $this->assertSame('no_results', $response->error);
    }
}
