<?php

namespace EduLazaro\Larascraper\Tests\Runners;

use EduLazaro\Larascraper\Runners\HttpRunner;
use EduLazaro\Larascraper\Tests\BaseTestCase;
use EduLazaro\Larascraper\Tests\Support\TestScraper;
use Illuminate\Support\Facades\Http;
use LogicException;

class HttpRunnerTest extends BaseTestCase
{
    public function test_it_fetches_a_url_and_returns_a_normalized_array(): void
    {
        Http::fake([
            'example.com/*' => Http::response('<html><head><title>Hi</title></head></html>', 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]),
        ]);

        $result = HttpRunner::on('https://example.com/page')->run();

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('<title>Hi</title>', $result['html']);
        $this->assertNull($result['error']);
        $this->assertNull($result['file']);
        $this->assertSame('text/html; charset=utf-8', $result['contentType']);
    }

    public function test_it_reports_a_failed_status(): void
    {
        Http::fake([
            'example.com/*' => Http::response('Not found', 404),
        ]);

        $result = HttpRunner::on('https://example.com/missing')->run();

        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['status']);
        $this->assertSame('HTTP 404', $result['error']);
    }

    public function test_it_does_not_support_browser_actions(): void
    {
        $this->expectException(LogicException::class);

        HttpRunner::on('https://example.com')->withActions([
            ['type' => 'click', 'selector' => '#go'],
        ]);
    }

    public function test_a_scraper_can_use_the_http_driver_end_to_end(): void
    {
        Http::fake([
            '*' => Http::response('<html><head><title>Bike 4</title></head></html>', 200),
        ]);

        $result = TestScraper::scrape('https://shop.test/bikes/4')
            ->driver('http')
            ->run();

        $this->assertTrue($result->success);
        $this->assertSame(['title' => 'Bike 4'], $result->data);
    }

    public function test_an_unknown_driver_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TestScraper::scrape('https://shop.test')->driver('carrier-pigeon');
    }

    public function test_http_driver_with_actions_fails_fast(): void
    {
        Http::fake();

        // A programming error (actions on the http driver) must surface
        // immediately, not be swallowed by the retry loop.
        $this->expectException(LogicException::class);

        TestScraper::scrape('https://shop.test')
            ->driver('http')
            ->click('#go')
            ->run();

        Http::assertNothingSent();
    }
}
