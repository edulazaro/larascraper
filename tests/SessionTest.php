<?php

namespace EduLazaro\Larascraper\Tests;

use EduLazaro\Larascraper\Exceptions\RequestException;
use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Support\Session;
use EduLazaro\Larascraper\Tests\Support\NonCookieRunner;
use Illuminate\Support\Facades\Http;

/**
 * Covers the shared cookie jar (Support\Session) at two levels:
 *
 *   - The value object itself: per-host storage, last-wins merge on store(), and
 *     isolation between hosts.
 *   - Its continuity through a Scraper: one Session handed to an instance via
 *     useSession() accumulates Set-Cookie from request 1 and replays it as the
 *     Cookie header on request 2 (driven by Http::fake, so no browser/network).
 *
 * The jar is transport state: it is mutated in place (shared by reference) and
 * never surfaces on ScraperResponse.
 */
class SessionTest extends BaseTestCase
{
    /**
     * store() merges into a host's bucket with last-wins on name collisions,
     * and cookiesFor() reads that bucket back.
     */
    public function test_store_merges_per_host_with_last_wins(): void
    {
        $session = new Session();

        $session->store('a.test', ['x' => '1', 'y' => '2']);
        $session->store('a.test', ['y' => '9', 'z' => '3']);

        $this->assertSame(['x' => '1', 'y' => '9', 'z' => '3'], $session->cookiesFor('a.test'));
    }

    /**
     * Cookies are scoped per host: two hosts never leak into each other, and an
     * unknown host reads back as an empty map.
     */
    public function test_cookies_are_isolated_between_hosts(): void
    {
        $session = new Session();

        $session->store('a.test', ['sid' => 'aaa']);
        $session->store('b.test', ['sid' => 'bbb']);

        $this->assertSame(['sid' => 'aaa'], $session->cookiesFor('a.test'));
        $this->assertSame(['sid' => 'bbb'], $session->cookiesFor('b.test'));
        $this->assertSame([], $session->cookiesFor('c.test'));
    }

    /**
     * store() with an empty map is a no-op: it neither clears nor creates a host
     * bucket, and all() exposes the whole jar for inspection.
     */
    public function test_empty_store_is_a_noop_and_all_exposes_the_jar(): void
    {
        $session = new Session();

        $session->store('a.test', []);
        $this->assertSame([], $session->all());

        $session->store('a.test', ['x' => '1']);
        $this->assertSame(['a.test' => ['x' => '1']], $session->all());
    }

    /**
     * A Session handed to a scraper via useSession() persists across scrapes on
     * the same instance: request 1 sets Set-Cookie=SID=abc, and request 2 (same
     * host) sends Cookie: SID=abc. Proves the jar is shared and mutated.
     */
    public function test_session_continuity_replays_a_cookie_on_the_next_request(): void
    {
        Http::fake([
            'https://shop.test/a' => Http::response('<html><title>A</title></html>', 200, [
                'Set-Cookie' => 'SID=abc; Path=/',
            ]),
            'https://shop.test/b' => Http::response('<html><title>B</title></html>', 200),
        ]);

        $session = new Session();
        $scraper = SessionScraper::make()->useSession($session);

        // Request 1 establishes the cookie; the jar accumulates it.
        $scraper->scrape('https://shop.test/a')->run();
        $this->assertSame(['SID' => 'abc'], $session->cookiesFor('shop.test'));

        // Request 2, same instance + jar, replays it as an outbound Cookie header.
        $scraper->scrape('https://shop.test/b')->run();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/b')
                && in_array('SID=abc', $request->header('Cookie'), true);
        });
    }

    /**
     * Explicit per-call cookies win over the jar: seeding the jar with SID=jar
     * and issuing a scrape that sets SID=override outbound must send the override,
     * not the jar value. Locks the anti-clobber merge order in FetchBuilder.
     */
    public function test_explicit_cookies_win_over_session_cookies(): void
    {
        Http::fake([
            '*' => Http::response('<html><title>ok</title></html>', 200),
        ]);

        $session = new Session();
        $session->store('shop.test', ['SID' => 'jar']);

        $scraper = SessionScraper::make()->useSession($session);
        $scraper->scrape('https://shop.test/x')->cookies(['SID' => 'override'])->run();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $cookies = $request->header('Cookie');

            return in_array('SID=override', $cookies, true)
                && !in_array('SID=jar', $cookies, true);
        });
    }

    /**
     * withSession() (static config -> PendingScraper) and the shared jar thread
     * a cookie across two SEPARATE configured runs: the jar is not owned by any
     * instance, so SID set on run 1 rides into run 2.
     */
    public function test_withSession_threads_the_jar_across_configured_runs(): void
    {
        Http::fake([
            'https://shop.test/a' => Http::response('<html><title>A</title></html>', 200, [
                'Set-Cookie' => 'SID=abc; Path=/',
            ]),
            'https://shop.test/b' => Http::response('<html><title>B</title></html>', 200),
        ]);

        $session = new Session();

        SessionScraper::withSession($session)->run('https://shop.test/a');
        $this->assertSame(['SID' => 'abc'], $session->cookiesFor('shop.test'));

        SessionScraper::withSession($session)->run('https://shop.test/b');

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/b')
                && in_array('SID=abc', $request->header('Cookie'), true);
        });
    }

    /**
     * The FetchBuilder::session() setter threads the jar for a single request
     * chain (no useSession() on the scraper): request 1 establishes SID and
     * request 2 replays it.
     */
    public function test_fetchbuilder_session_setter_threads_the_jar(): void
    {
        Http::fake([
            'https://shop.test/a' => Http::response('<html><title>A</title></html>', 200, [
                'Set-Cookie' => 'SID=abc; Path=/',
            ]),
            'https://shop.test/b' => Http::response('<html><title>B</title></html>', 200),
        ]);

        $session = new Session();
        $scraper = SessionScraper::make();

        $scraper->scrape('https://shop.test/a')->session($session)->run();
        $scraper->scrape('https://shop.test/b')->session($session)->run();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/b')
                && in_array('SID=abc', $request->header('Cookie'), true);
        });
    }

    /**
     * A failed response must not clobber the established session: SID=good set on
     * a successful request survives even though a later 5xx response carries
     * Set-Cookie=SID=bad. Locks the "store only on success" rule.
     */
    public function test_failed_response_cookies_do_not_clobber_the_session(): void
    {
        Http::fake([
            'https://shop.test/a' => Http::response('<html><title>A</title></html>', 200, [
                'Set-Cookie' => 'SID=good; Path=/',
            ]),
            'https://shop.test/b' => Http::response('nope', 500, [
                'Set-Cookie' => 'SID=bad; Path=/',
            ]),
        ]);

        $session = new Session();
        $scraper = SessionScraper::make()->useSession($session);

        $scraper->scrape('https://shop.test/a')->run();
        $this->assertSame(['SID' => 'good'], $session->cookiesFor('shop.test'));

        try {
            $scraper->scrape('https://shop.test/b')->run();
            $this->fail('Expected a RequestException on the failed response.');
        } catch (RequestException $e) {
            // Expected: a request-level failure surfaces as a RequestException.
        }

        // The failed response's Set-Cookie never overwrote the good session cookie.
        $this->assertSame(['SID' => 'good'], $session->cookiesFor('shop.test'));
    }

    /**
     * On a driver that cannot carry outbound cookies (supportsCookies() === false,
     * as the browser driver), a shared Session is a documented no-op: the jar's
     * cookies are never merged into the outbound request (so the runner does not
     * throw) and the response's Set-Cookie is never accumulated back into the jar.
     */
    public function test_session_is_a_noop_on_a_driver_without_cookie_support(): void
    {
        NonCookieRunner::reset();

        $session = new Session();
        $session->store('shop.test', ['SID' => 'seed']);

        $scraper = BrowserlikeSessionScraper::make()->useSession($session);

        // Must NOT throw despite the jar holding a cookie for this host.
        $response = $scraper->scrape('https://shop.test/a')->run();

        $this->assertTrue($response->success);
        // The jar's cookies were never handed to the non-cookie runner.
        $this->assertSame([], NonCookieRunner::$received);
        // The response's Set-Cookie was NOT accumulated into the jar.
        $this->assertSame(['SID' => 'seed'], $session->cookiesFor('shop.test'));
    }
}

/**
 * A scraper pinned to a non-cookie driver (mapping a 'stub' driver to
 * {@see NonCookieRunner}), so the browser-driver cookie stance can be exercised
 * without launching a real browser or performing any I/O.
 */
class BrowserlikeSessionScraper extends Scraper
{
    /** Route to the non-cookie stub runner. */
    protected string $driver = 'stub';

    /** @var array<string, class-string> A single driver that cannot carry cookies. */
    protected array $drivers = ['stub' => NonCookieRunner::class];

    /** One attempt: no retry loop. */
    protected int $tries = 1;

    protected function handle(string $url): ScraperResponse
    {
        return $this->scrape($url)->run();
    }
}

/**
 * Minimal http-driver scraper for the session-continuity test: it just fetches
 * the URL and returns the raw html, so the cookie plumbing is what is exercised.
 */
class SessionScraper extends Scraper
{
    /** Force the HTTP runner so Http::fake() drives the request in tests. */
    protected string $driver = 'http';

    /** One attempt: no retry loop. */
    protected int $tries = 1;

    protected function handle(string $url): ScraperResponse
    {
        return $this->scrape($url)->run();
    }
}
