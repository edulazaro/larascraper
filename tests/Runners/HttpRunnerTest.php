<?php

namespace EduLazaro\Larascraper\Tests\Runners;

use EduLazaro\Larascraper\Runners\HttpRunner;
use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Tests\BaseTestCase;
use EduLazaro\Larascraper\Tests\Support\TestScraper;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
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

    public function test_a_binary_response_is_exposed_as_a_captured_file(): void
    {
        Http::fake([
            '*' => Http::response('%PDF-1.4 fake bytes', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $result = HttpRunner::on('https://example.com/law.pdf')->run();

        $this->assertTrue($result['success']);
        $this->assertSame('', $result['html']);                       // binary does not land in html
        $this->assertSame('application/pdf', $result['contentType']);
        $this->assertSame('%PDF-1.4 fake bytes', base64_decode($result['file']));
    }

    public function test_a_json_response_is_kept_as_text(): void
    {
        Http::fake([
            '*' => Http::response('{"ok":true}', 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        $result = HttpRunner::on('https://example.com/api')->run();

        $this->assertSame('{"ok":true}', $result['html']);
        $this->assertNull($result['file']);
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

        $result = TestScraper::run('https://shop.test/bikes/4');

        $this->assertTrue($result->success);
        $this->assertSame(['title' => 'Bike 4'], $result->data);
    }

    public function test_an_unknown_driver_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TestScraper::make()->scrape('https://shop.test')->driver('carrier-pigeon');
    }

    public function test_http_driver_with_actions_fails_fast(): void
    {
        Http::fake();

        // A programming error (actions on the http driver) must surface
        // immediately from fetch(), not be swallowed by the retry loop.
        $scraper = new class extends Scraper {
            protected string $driver = 'http';
            protected int $tries = 1;

            protected function handle(string $url): ScraperResponse
            {
                return $this->scrape($url)->click('#go')->run();
            }
        };

        $this->expectException(LogicException::class);

        $scraper->callHandle(['https://shop.test']);
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

        // The POST wiring is exercised end to end from inside a scraper's
        // handle(), through the FetchBuilder's post() setter.
        $scraper = new class extends Scraper {
            protected string $driver = 'http';
            protected int $tries = 1;

            protected function handle(string $url): ScraperResponse
            {
                return $this->scrape($url)->post(['id' => 4], 'form')->run();
            }
        };

        $result = $scraper->callHandle(['https://shop.test/bikes/4']);

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
