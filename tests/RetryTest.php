<?php

namespace EduLazaro\Larascraper\Tests;

use EduLazaro\Larascraper\Exceptions\RequestException;
use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\RequestResponse;
use EduLazaro\Larascraper\Support\ScraperResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Covers the bounded retry engine in FetchBuilder::fetch(): a retriable status is
 * retried up to $tries, a non-retriable one fails fast, and a transport error
 * surfaces as status 0. Driven by Http::fake with retryDelay = 0 (no real sleeps).
 */
class RetryTest extends BaseTestCase
{
    public function test_it_retries_a_retriable_status_until_it_succeeds(): void
    {
        Http::fakeSequence()
            ->push('busy', 503)
            ->push('busy', 503)
            ->push('<html><body>ok</body></html>', 200);

        $r = RetryScraper::run('https://shop.test/x');

        $this->assertTrue($r->success);
        Http::assertSentCount(3);   // two 503s retried, the third 200 succeeded
    }

    public function test_it_fails_fast_on_a_non_retriable_status(): void
    {
        Http::fake(['*' => Http::response('nope', 404)]);

        try {
            RetryScraper::run('https://shop.test/x');
            $this->fail('Expected a RequestException.');
        } catch (RequestException $e) {
            $this->assertSame(404, $e->response->status);
        }

        Http::assertSentCount(1);   // 404 is not retried
    }

    public function test_a_transport_exception_surfaces_as_status_zero(): void
    {
        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        try {
            RetryScraper::run('https://shop.test/x');
            $this->fail('Expected a RequestException.');
        } catch (RequestException $e) {
            $this->assertSame(0, $e->response->status);
            $this->assertNotNull($e->response->error);
        }
    }

    public function test_request_response_successful_boundary(): void
    {
        $this->assertFalse((new RequestResponse(status: 199))->successful());
        $this->assertTrue((new RequestResponse(status: 200))->successful());
        $this->assertTrue((new RequestResponse(status: 299))->successful());
        $this->assertFalse((new RequestResponse(status: 300))->successful());
    }
}

/**
 * Scraper that actually drives the fetch retry loop: up to three attempts, no
 * sleep between them in tests.
 */
class RetryScraper extends Scraper
{
    /** Force the HTTP runner so Http::fake() drives the request in tests. */
    protected string $driver = 'http';

    protected int $tries = 3;
    protected int $retryDelay = 0;

    protected function handle(string $url): ScraperResponse
    {
        return $this->scrape($url)->run();
    }
}
