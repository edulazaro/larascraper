<?php

namespace EduLazaro\Larascraper\Tests\Support;

use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\ScraperResponse;

/**
 * Canonical happy-path scraper used across the suite.
 *
 * It pins the `http` driver so the CI tests can drive it with Http::fake()
 * (no headless browser required) and sets a single try so failures surface
 * immediately without retry sleeps. Its handle() fetches the URL, parses the
 * document with {@see TitleCrawler}, and returns the resulting
 * {@see ScraperResponse}.
 */
class TestScraper extends Scraper
{
    /** Force the HTTP runner so Http::fake() drives the request in tests. */
    protected string $driver = 'http';

    /** One attempt: no retry loop in the fixtures. */
    protected int $tries = 1;

    /**
     * Fetch the URL and parse its <title> via the crawler.
     */
    protected function handle(string $url): ScraperResponse
    {
        return $this->scrape($url)->crawl(TitleCrawler::class)->run();
    }
}
