<?php

namespace EduLazaro\Larascraper\Tests\Support;

use EduLazaro\Larascraper\Crawler;
use EduLazaro\Larascraper\Exceptions\ScrapeException;

/**
 * Crawler that simulates a content-level failure (a captcha wall, a blocked
 * page, etc.) by throwing a {@see ScrapeException} from handle().
 *
 * A ScrapeException thrown INSIDE a crawler is caught by the crawl terminal
 * and turned into a ScraperResponse with success = false and error = 'captcha'
 * (it does NOT propagate out of run()). This fixture proves that behaviour.
 */
class FailingCrawler extends Crawler
{
    /**
     * @return array<string, mixed>
     *
     * @throws ScrapeException always, to simulate a content failure.
     */
    protected function handle(): array
    {
        throw new ScrapeException('captcha');
    }
}
