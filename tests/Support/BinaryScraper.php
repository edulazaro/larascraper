<?php

namespace EduLazaro\Larascraper\Tests\Support;

use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\CapturedFile;
use EduLazaro\Larascraper\Support\ScraperResponse;

/**
 * Scraper that drives the binary-capture terminal.
 *
 * The file() method fetches the URL and returns the captured binary as a
 * {@see CapturedFile} via the ->capture()->file() chain. Under the `http`
 * driver capture() is a no-op action (it only means something to the browser
 * runner), but it is harmless: the binary body of the HTTP response is exposed
 * through request->file, which FetchBuilder::file() reads. This lets the
 * capture terminal be exercised against an Http::fake() %PDF response.
 */
class BinaryScraper extends Scraper
{
    /** Force the HTTP runner so Http::fake() drives the request in tests. */
    protected string $driver = 'http';

    /** One attempt: no retry loop in the fixtures. */
    protected int $tries = 1;

    /**
     * Fetch the URL and return its captured binary body.
     */
    public function file(string $url): CapturedFile
    {
        return $this->scrape($url)->capture()->file();
    }

    /**
     * Default entry point: the bare FetchBuilder::run() terminal, which returns
     * the raw response body as the ScraperResponse data.
     */
    protected function handle(string $url): ScraperResponse
    {
        return $this->scrape($url)->run();
    }
}
