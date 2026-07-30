<?php

namespace EduLazaro\Larascraper\Tests\Support;

use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Exceptions\ScrapeException;
use EduLazaro\Larascraper\Support\ScraperResponse;

/**
 * Scraper whose own handle() throws a {@see ScrapeException} after a successful
 * fetch and parse.
 *
 * A ScrapeException raised by the scraper's OWN handle() is caught by
 * Scraper::handleToResponse() (just as one thrown inside a Crawler is caught by
 * Crawl::run()) and folded into a ScraperResponse with success=false; it does
 * NOT reach the caller. This fixture proves the handle() path yields
 * success=false, not a propagated exception.
 */
class ThrowingScraper extends Scraper
{
    /** Force the HTTP runner so Http::fake() drives the request in tests. */
    protected string $driver = 'http';

    /** One attempt: no retry loop in the fixtures. */
    protected int $tries = 1;

    /**
     * Fetch and parse the URL, then deliberately raise a scrape-level failure.
     *
     * @throws ScrapeException always, from the scraper's own handle().
     */
    protected function handle(string $url): ScraperResponse
    {
        $this->scrape($url)->crawl(TitleCrawler::class)->run();

        throw new ScrapeException('no_results');
    }
}
