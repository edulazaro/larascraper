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

    public function test_it_sends_a_post_with_a_form_body(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        HttpRunner::on('https://api.test/search')
            ->method('POST')
            ->body(['databasematch' => 'ANDORRA', 'start' => 1], 'form')
            ->run();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->method() === 'POST'
                && $request->isForm()
                && $request['databasematch'] === 'ANDORRA'
                && (string) $request['start'] === '1';
        });
    }

    public function test_it_sends_a_post_with_a_json_body(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        HttpRunner::on('https://api.test/search')
            ->method('POST')
            ->body(['q' => 'x'], 'json')
            ->run();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->method() === 'POST'
                && $request->isJson()
                && $request['q'] === 'x';
        });
    }

    public function test_it_sends_cookies(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        HttpRunner::on('https://api.test/x')
            ->cookies(['JSESSIONID' => 'abc123'])
            ->run();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $cookie = $request->header('Cookie');
            return !empty($cookie) && str_contains($cookie[0], 'JSESSIONID=abc123');
        });
    }

    public function test_a_scraper_can_post_via_the_http_driver(): void
    {
        Http::fake(['*' => Http::response('<html><head><title>Bike 4</title></head></html>', 200)]);

        $result = TestScraper::scrape('https://shop.test/bikes/4')
            ->driver('http')
            ->post(['id' => 4], 'form')
            ->run();

        $this->assertTrue($result->success);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->method() === 'POST' && (string) $r['id'] === '4');
    }

    public function test_browser_driver_rejects_post(): void
    {
        $this->expectException(LogicException::class);

        \EduLazaro\Larascraper\Runners\PuppeteerRunner::on('https://example.com')->method('POST');
    }

    public function test_it_returns_response_cookies(): void
    {
        Http::fake([
            '*' => Http::response('ok', 200, ['Set-Cookie' => 'JSESSIONID=abc123; Path=/; HttpOnly']),
        ]);

        $result = HttpRunner::on('https://api.test/login')->run();

        $this->assertArrayHasKey('cookies', $result);
        $this->assertSame('abc123', $result['cookies']['JSESSIONID'] ?? null);
    }
}
