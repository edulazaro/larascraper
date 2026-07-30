<?php

namespace EduLazaro\Larascraper;

use Symfony\Component\DomCrawler\Crawler as DomCrawler;

/**
 * Abstract parsing base, independent of how the HTML was fetched.
 *
 * A Crawler receives a raw HTML string, wraps it in a Symfony DomCrawler, and
 * lets a subclass extract whatever it needs from that document via handle():
 *
 *   class BikeCrawler extends Crawler
 *   {
 *       protected function handle(): array
 *       {
 *           return [
 *               'title' => $this->filter('title')->text(''),
 *               'price' => $this->filter('.price')->text(''),
 *           ];
 *       }
 *   }
 *
 * Because a Crawler only knows about HTML (never about the network, the driver,
 * retries, or cookies), the same crawler can be reused across scrapers, against
 * fixtures in tests, or against HTML obtained by any other means.
 *
 * When a subclass detects a content-level failure (a captcha page, no results,
 * an unexpected layout, etc.) it should:
 *
 *   throw new \EduLazaro\Larascraper\Exceptions\ScrapeException('no_results');
 *
 * A ScrapeException thrown from inside a Crawler is CAUGHT by the crawl
 * terminal that drove it and turned into a
 * {@see \EduLazaro\Larascraper\Support\ScraperResponse} with success = false and
 * error set to the exception message (see spec section 5). It does NOT bubble
 * out of run(); the caller inspects $response->success / $response->error.
 */
abstract class Crawler
{
    /** The parsed document backing every filter() call. */
    protected DomCrawler $dom;

    /**
     * Wrap the given HTML in a Symfony DomCrawler ready for querying.
     */
    public function __construct(string $html)
    {
        $this->dom = new DomCrawler($html);
    }

    /**
     * Public entry point. Delegates to the subclass handle() so callers never
     * touch the protected extraction logic directly.
     */
    public function parse(): mixed
    {
        return $this->handle();
    }

    /**
     * Extract and return the data for this crawler. Implementations use
     * $this->filter() (and, when needed, $this->html()) to read the document,
     * and may throw a ScrapeException to signal a content-level failure.
     */
    abstract protected function handle(): mixed;

    /**
     * Filter the document by a CSS selector, returning a Symfony DomCrawler
     * node list for further traversal.
     */
    protected function filter(string $selector): DomCrawler
    {
        return $this->dom->filter($selector);
    }

    /**
     * The full HTML of the wrapped document.
     */
    protected function html(): string
    {
        return $this->dom->html();
    }
}
