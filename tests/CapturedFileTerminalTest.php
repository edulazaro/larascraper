<?php

namespace EduLazaro\Larascraper\Tests;

use EduLazaro\Larascraper\Exceptions\ScrapeException;
use EduLazaro\Larascraper\Support\CapturedFile;
use EduLazaro\Larascraper\Tests\Support\BinaryScraper;
use Illuminate\Support\Facades\Http;

/**
 * Exercises the ->capture()->file() terminal against the `http` driver.
 *
 * The capture() action declares "expect a binary body" and, on the browser
 * driver, drives Puppeteer to intercept the download. Under the http driver it
 * is a harmless no-op: the runner already detects a binary response by its
 * content type (or magic bytes) and exposes it as the RequestResponse::$file,
 * so ->capture()->file() returns it with no real browser action at all. The
 * {@see BinaryScraper} fixture pins the http driver so Http::fake() drives the
 * request. The genuine Puppeteer capture path needs a headless browser and is
 * only documented here via a skipped test.
 */
class CapturedFileTerminalTest extends BaseTestCase
{
    public function test_the_capture_file_terminal_returns_a_captured_file_for_a_binary_response(): void
    {
        Http::fake([
            '*' => Http::response('%PDF-1.4 fake bytes', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $file = BinaryScraper::make()->file('https://shop.test/law.pdf');

        $this->assertInstanceOf(CapturedFile::class, $file);
        $this->assertSame('application/pdf', $file->contentType());
        $this->assertSame('%PDF-1.4 fake bytes', $file->bytes());
    }

    public function test_the_capture_file_terminal_throws_when_the_response_is_not_binary(): void
    {
        Http::fake([
            '*' => Http::response('<html><head><title>Not a file</title></head></html>', 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]),
        ]);

        $this->expectException(ScrapeException::class);
        $this->expectExceptionMessage('no_file');

        BinaryScraper::make()->file('https://shop.test/page');
    }

    public function test_the_real_puppeteer_capture_path_requires_a_browser(): void
    {
        // The scripted capture flow used against a live page,
        //
        //   $this->scrape($url)->click('a.datasheet')->capture()->file();
        //
        // adds a genuine browser action (the click) before capture() and drives
        // the whole recipe through the Puppeteer runner, which intercepts the
        // download. The `http` driver rejects real browser actions, so this path
        // is only reachable with the default `browser` driver, which cannot run
        // in CI. We do NOT launch a headless Chromium here.
        $this->markTestSkipped('requires a headless browser');
    }
}
