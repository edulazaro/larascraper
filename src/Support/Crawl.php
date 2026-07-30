<?php

namespace EduLazaro\Larascraper\Support;

use EduLazaro\Larascraper\Exceptions\ScrapeException;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use LogicException;

/**
 * The terminal object returned by FetchBuilder::crawl().
 *
 * It runs the shared fetch (via FetchBuilder::fetch(), so the request happens
 * exactly once) and then shapes the result in one of two modes decided at
 * construction:
 *
 *   - Crawler-class mode: run() instantiates the Crawler with the fetched html,
 *     calls parse(), and returns a ScraperResponse. A ScrapeException thrown by
 *     the Crawler (captcha, no_results, ...) is caught and turned into a
 *     ScraperResponse with success=false and the error code; it does NOT bubble.
 *
 *   - Selector mode: text()/texts() extract inline with a Symfony DomCrawler.
 *     Calling run() in this mode is a misuse and throws LogicException.
 */
class Crawl
{
    /**
     * @param FetchBuilder $builder The fetch chain to draw the response from.
     * @param string $target A Crawler class-string, or a CSS selector.
     * @param bool $isCrawlerClass Whether $target is a Crawler subclass (class mode).
     */
    public function __construct(
        protected FetchBuilder $builder,
        protected string $target,
        protected bool $isCrawlerClass,
    ) {
    }

    /**
     * Fetch and parse through the Crawler class, returning a ScraperResponse.
     *
     * @return ScraperResponse
     * @throws LogicException When called in selector mode (use text()/texts()).
     */
    public function run(): ScraperResponse
    {
        $req = $this->builder->fetch();

        if (!$this->isCrawlerClass) {
            throw new LogicException('crawl(selector)->run() is not valid; use text()/texts() for inline selectors.');
        }

        try {
            $data = (new $this->target($req->html))->parse();

            return new ScraperResponse(data: $data, success: true);
        } catch (ScrapeException $e) {
            return new ScraperResponse(data: null, success: false, error: $e->getMessage());
        }
    }

    /**
     * Fetch and return the text of the first element matching the selector.
     *
     * @return string The trimmed text, or '' when nothing matched.
     */
    public function text(): string
    {
        $req = $this->builder->fetch();

        $dom = new DomCrawler($req->html);
        $filtered = $dom->filter($this->target);

        return $filtered->count() ? $filtered->first()->text('') : '';
    }

    /**
     * Fetch and return the text of every element matching the selector.
     *
     * @return array<int, string> The trimmed texts, or [] when nothing matched.
     */
    public function texts(): array
    {
        $dom = new DomCrawler($this->builder->fetch()->html);

        return $dom->filter($this->target)->each(fn(DomCrawler $node) => $node->text(''));
    }
}
