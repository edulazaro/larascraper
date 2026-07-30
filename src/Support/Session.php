<?php

namespace EduLazaro\Larascraper\Support;

/**
 * A shared cookie jar threaded through a whole crawl by reference.
 *
 * A Scraper is session-AWARE but does not own the jar: one mutable Session is
 * created once (by a Spider, or by a caller) and handed to every Scraper via
 * useSession(), so a sequence of requests keeps a single, accumulating set of
 * cookies. FetchBuilder::fetch() reads the jar before a request (merging the
 * host's cookies UNDER any explicit per-call cookies) and writes Set-Cookie back
 * into it after, so authentication established on one page rides along to the
 * next.
 *
 * Cookies are held per HOST (the request URL's host), so two hosts never leak
 * into each other. The jar is deliberately kept off ScraperResponse: cookies are
 * transport state, never scrape data.
 */
class Session
{
    /**
     * The cookie store, keyed by host then cookie name.
     *
     * @var array<string, array<string, string>>
     */
    protected array $cookies = [];

    /**
     * The cookies known for a host, as a name => value map.
     *
     * @param string $host The request URL's host (e.g. 'shop.test').
     * @return array<string, string>
     */
    public function cookiesFor(string $host): array
    {
        return $this->cookies[$host] ?? [];
    }

    /**
     * Merge cookies into a host's bucket, last-wins on name collisions.
     *
     * Called with the Set-Cookie map after each request so the jar accumulates.
     * An empty map is a no-op (it neither clears nor creates a host bucket).
     *
     * @param string $host The request URL's host.
     * @param array<string, string> $cookies New cookies to merge (name => value).
     * @return void
     */
    public function store(string $host, array $cookies): void
    {
        if (empty($cookies)) {
            return;
        }

        $this->cookies[$host] = array_merge($this->cookies[$host] ?? [], $cookies);
    }

    /**
     * The whole jar, keyed by host then cookie name, for inspection.
     *
     * @return array<string, array<string, string>>
     */
    public function all(): array
    {
        return $this->cookies;
    }
}
