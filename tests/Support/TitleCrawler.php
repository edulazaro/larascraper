<?php

namespace EduLazaro\Larascraper\Tests\Support;

use EduLazaro\Larascraper\Crawler;

/**
 * Minimal crawler used by {@see TestScraper} and the crawl tests.
 *
 * It reads the document's <title> and returns it under a `title` key, proving
 * the class-mode crawl path (crawl(TitleCrawler::class)->run()) yields a
 * ScraperResponse whose data is the array returned here.
 */
class TitleCrawler extends Crawler
{
    /**
     * Extract the page title.
     *
     * @return array{title: string}
     */
    protected function handle(): array
    {
        return [
            'title' => $this->filter('title')->text(''),
        ];
    }
}
