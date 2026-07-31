<?php

namespace EduLazaro\Larascraper;

use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use LogicException;

/**
 * Abstract parsing base, independent of how the document was fetched.
 *
 * A Crawler receives a raw document and lets a subclass extract whatever it
 * needs from it via handle(). The input is generic: it can be an HTML string, an
 * XML string, an arbitrary text payload parsed by regex, or even an array of
 * named parts when a single fetch yields more than one document. The Crawler
 * never knows about the network, the driver, retries or cookies, so the same
 * crawler can be reused across scrapers, against fixtures in tests, or against a
 * document obtained by any other means.
 *
 * There are three ways to read the input, pick the one that fits the format:
 *
 *   - $this->filter($selector)          CSS query over the HTML DomCrawler.
 *   - $this->filter($selector, 'xml')   CSS query over an XML DomCrawler.
 *   - $this->raw()                      The untouched input (string OR array),
 *                                       to run regex, simplexml, json_decode or
 *                                       to read the named parts of an array.
 *
 * HTML example:
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
 *   BikeCrawler::run($html);
 *
 * XML example (CSS selectors compile to XPath, so 'item' matches <item>):
 *
 *   class FeedCrawler extends Crawler
 *   {
 *       protected function handle(): array
 *       {
 *           return $this->filter('item', 'xml')
 *               ->each(fn ($node) => $node->filter('title')->text(''));
 *       }
 *   }
 *
 * Raw / multi-part example:
 *
 *   class SplitCrawler extends Crawler
 *   {
 *       protected function handle(): array
 *       {
 *           $parts = $this->raw();          // ['meta' => '...', 'body' => '...']
 *           return ['id' => $parts['meta']];
 *       }
 *   }
 *
 *   SplitCrawler::run(['meta' => $metaXml, 'body' => $bodyHtml]);
 *
 * run($input) is the standard entry, consistent with Scraper::run() and
 * Spider::run(). parse() is kept as a legacy alias.
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
    /** The raw input backing raw() and, for a string, the parsed documents. */
    protected mixed $input;

    /**
     * The parsed HTML document backing filter() and html().
     *
     * Only initialised when the input is a string; for a non-string input (an
     * array of parts) it stays unset and those helpers are unavailable.
     */
    protected DomCrawler $dom;

    /**
     * A separate XML DomCrawler, built lazily on the first filter($sel, 'xml')
     * call and cached for later ones. Null until then.
     */
    protected ?DomCrawler $xmlDom = null;

    /**
     * Wrap the given input ready for querying.
     *
     * A string input is treated as an HTML document and eagerly wrapped in a
     * Symfony DomCrawler, so $this->filter() / $this->html() / $this->dom work
     * as before. A non-string input (e.g. an array of named parts) is stored
     * untouched and read through $this->raw(); the HTML DomCrawler is not built.
     *
     * @param mixed $input The raw document: an HTML/XML/text string, or an array.
     */
    public function __construct(mixed $input)
    {
        $this->input = $input;

        if (is_string($input)) {
            $this->dom = new DomCrawler($input);
        }
    }

    /**
     * Fluent alternative to `new BikeCrawler($input)`, so a standalone parse
     * reads as `BikeCrawler::create($input)->parse()`.
     *
     * @param mixed $input The raw document: an HTML/XML/text string, or an array.
     * @return static
     */
    public static function create(mixed $input): static
    {
        return new static($input);
    }

    /**
     * Standard entry point: create a crawler for the input and parse it.
     *
     * This mirrors Scraper::run() and Spider::run() and is the preferred way to
     * run a crawler standalone: `BikeCrawler::run($input)`. It returns the data
     * the subclass handle() produced.
     *
     * @param mixed $input The raw document: an HTML/XML/text string, or an array.
     * @return mixed The parsed data.
     */
    public static function run(mixed $input): mixed
    {
        return static::create($input)->parse();
    }

    /**
     * Parse the input by delegating to the subclass handle().
     *
     * LEGACY: this is a retained alias kept for back-compat (the crawl terminal
     * and existing `create($input)->parse()` callers rely on it). Prefer
     * `Class::run($input)`, which is consistent with the rest of larascraper.
     *
     * @return mixed The parsed data.
     */
    public function parse(): mixed
    {
        return $this->handle();
    }

    /**
     * Extract and return the data for this crawler. Implementations use
     * $this->filter() (HTML or XML) and/or $this->raw() to read the document,
     * and may throw a ScrapeException to signal a content-level failure.
     */
    abstract protected function handle(): mixed;

    /**
     * Filter the document by a CSS selector, returning a Symfony DomCrawler node
     * list for further traversal.
     *
     * $as selects the backing document:
     *   - 'html' (default) queries the eagerly-built HTML DomCrawler, byte for
     *     byte the old single-argument behaviour.
     *   - 'xml' lazily builds (and caches) a separate DomCrawler from the input
     *     via addXmlContent(), so <bloque> in an XML payload is matched. CSS
     *     selectors compile to XPath, and the returned node still allows
     *     ->filterXPath() chaining for namespaced XML.
     *
     * Both modes require a string input; a non-string input has no document to
     * query, so a clear LogicException is thrown pointing at raw().
     *
     * @param string $selector The CSS selector.
     * @param string $as 'html' (default) or 'xml'.
     * @return DomCrawler
     * @throws LogicException When the input is not a string.
     */
    protected function filter(string $selector, string $as = 'html'): DomCrawler
    {
        if (!is_string($this->input)) {
            throw new LogicException(
                'filter() needs a string document; got ' . get_debug_type($this->input) . '. Use raw() for non-string input.'
            );
        }

        if ($as === 'xml') {
            if ($this->xmlDom === null) {
                $this->xmlDom = new DomCrawler();
                $this->xmlDom->addXmlContent($this->input);
            }

            return $this->xmlDom->filter($selector);
        }

        return $this->dom->filter($selector);
    }

    /**
     * The raw input, exactly as the crawler was constructed with: the string for
     * a string document, or the whole array for a multi-part input. Use it to
     * parse with regex, simplexml, json_decode, or to read the array's parts.
     *
     * @return mixed
     */
    protected function raw(): mixed
    {
        return $this->input;
    }

    /**
     * The full HTML of the wrapped document. Valid only for a string (HTML)
     * input, where the HTML DomCrawler was built; a non-string input has no
     * document, so a clear LogicException is thrown pointing at raw().
     *
     * @return string
     * @throws LogicException When the input is not a string.
     */
    protected function html(): string
    {
        if (!is_string($this->input)) {
            throw new LogicException(
                'html() needs a string document; got ' . get_debug_type($this->input) . '. Use raw() for non-string input.'
            );
        }

        return $this->dom->html();
    }
}
