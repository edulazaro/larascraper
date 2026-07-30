<?php

namespace EduLazaro\Larascraper\Support;

/**
 * The scraper-layer result returned by Scraper::run().
 *
 * It is intentionally minimal: the parsed `data` plus a `success`/`error`
 * verdict at the scrape (content) level. The HTTP-layer facts (status, html,
 * cookies, a captured binary) are NOT here: they live on the scraper as
 * `$this->request` (a RequestResponse) during handle(), and on
 * RequestException::$response when a request-level failure is thrown.
 */
class ScraperResponse
{
    /**
     * @param mixed $data The parsed data returned by the scraper's handle() (or a Crawler's parse()).
     * @param bool $success Whether the scrape succeeded at the content level.
     * @param string|null $error The scrape-level error code when it failed, null otherwise.
     */
    public function __construct(
        public mixed $data = null,
        public bool $success = true,
        public ?string $error = null,
    ) {
    }
}
