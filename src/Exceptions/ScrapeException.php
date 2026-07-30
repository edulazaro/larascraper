<?php

namespace EduLazaro\Larascraper\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A scrape-level (content) failure: a captcha wall, an empty result set, or any
 * other condition where the request succeeded but the page did not yield what
 * the scraper needed.
 *
 * The message IS the scrape error code (e.g. 'captcha', 'no_results',
 * 'no_file'). Whether thrown inside a Crawler (caught by Crawl::run()) or from a
 * scraper's own handle() (caught by Scraper::handleToResponse()), it is folded
 * into a ScraperResponse with success=false and error=<message>, exactly like
 * $this->fail(). It does NOT reach the caller; only RequestException does.
 */
class ScrapeException extends RuntimeException
{
    /**
     * @param string $message The scrape error code.
     * @param int $code The exception code.
     * @param Throwable|null $previous The previous throwable used for chaining.
     */
    public function __construct(
        string $message = 'scrape_failed',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The scrape error code (the exception message).
     */
    public function error(): string
    {
        return $this->getMessage();
    }
}
