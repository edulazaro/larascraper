<?php

namespace EduLazaro\Larascraper\Tests;

use EduLazaro\Larascraper\Spider;
use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Tests\Support\TestScraper;
use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;

/**
 * Covers the Spider orchestrator engine (Spider::run() -> drive()): it walks the
 * targets() in order, runs one unit Scraper per target through the same wrapping
 * rules as Scraper::run(), applies the shouldVisit() filter, routes a failing
 * target to onError() without aborting the crawl, and threads ONE shared Session
 * through the whole run so a cookie set on target 1 is replayed on target 2.
 *
 * Everything is driven by Http::fake() on the http driver, with delay = 0, so no
 * real browser, network or sleep is involved.
 */
class SpiderTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CollectorSpider::reset();
    }

    /**
     * run() visits every target in order and hands each result to collect().
     */
    public function test_run_visits_every_target_in_order_and_collects(): void
    {
        Http::fake([
            '*' => Http::response('<html><title>ok</title></html>', 200),
        ]);

        CollectorSpider::$targets = [
            'https://shop.test/1',
            'https://shop.test/2',
            'https://shop.test/3',
        ];

        CollectorSpider::run();

        $this->assertSame(
            ['https://shop.test/1', 'https://shop.test/2', 'https://shop.test/3'],
            CollectorSpider::$collected,
        );
        // collect() received the parsed data (the <title>) for each target.
        $this->assertSame(
            [['title' => 'ok'], ['title' => 'ok'], ['title' => 'ok']],
            CollectorSpider::$data,
        );
        $this->assertSame([], CollectorSpider::$errored);
    }

    /**
     * shouldVisit() filters out a target: it is skipped, not fetched or collected.
     */
    public function test_shouldVisit_skips_a_target(): void
    {
        Http::fake([
            '*' => Http::response('<html><title>ok</title></html>', 200),
        ]);

        CollectorSpider::$targets = [
            'https://shop.test/1',
            'https://shop.test/skip',
            'https://shop.test/3',
        ];
        CollectorSpider::$skip = ['https://shop.test/skip'];

        CollectorSpider::run();

        $this->assertSame(
            ['https://shop.test/1', 'https://shop.test/3'],
            CollectorSpider::$collected,
        );
        Http::assertNotSent(fn (\Illuminate\Http\Client\Request $r) => str_contains($r->url(), '/skip'));
    }

    /**
     * A request-level failure on one target is routed to onError() and the crawl
     * continues with the remaining targets.
     */
    public function test_onError_is_called_for_a_failing_target(): void
    {
        Http::fake([
            'https://shop.test/bad' => Http::response('nope', 404),
            '*' => Http::response('<html><title>ok</title></html>', 200),
        ]);

        CollectorSpider::$targets = [
            'https://shop.test/1',
            'https://shop.test/bad',
            'https://shop.test/3',
        ];

        CollectorSpider::run();

        // The good targets still collected; the bad one went to onError().
        $this->assertSame(
            ['https://shop.test/1', 'https://shop.test/3'],
            CollectorSpider::$collected,
        );
        $this->assertSame(['https://shop.test/bad'], CollectorSpider::$errored);
    }

    /**
     * The ONE Session created by the engine persists a cookie set on target 1
     * into the outbound request for target 2 (same host).
     */
    public function test_one_session_threads_a_cookie_across_targets(): void
    {
        Http::fake([
            'https://shop.test/a' => Http::response('<html><title>A</title></html>', 200, [
                'Set-Cookie' => 'SID=abc; Path=/',
            ]),
            'https://shop.test/b' => Http::response('<html><title>B</title></html>', 200),
        ]);

        CollectorSpider::$targets = [
            'https://shop.test/a',
            'https://shop.test/b',
        ];

        CollectorSpider::run();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/b')
                && in_array('SID=abc', $request->header('Cookie'), true);
        });
    }

    /**
     * A spider that forgets to declare $scraper fails fast with a LogicException
     * from drive(), instead of masking the misconfiguration as a per-target
     * onError() for every target.
     */
    public function test_missing_scraper_class_fails_fast(): void
    {
        $this->expectException(LogicException::class);

        MisconfiguredSpider::run();
    }
}

/**
 * A deliberately broken spider fixture: it never declares $scraper, so drive()
 * must reject it up front rather than routing an uninitialized-property Error to
 * onError() for each target.
 */
class MisconfiguredSpider extends Spider
{
    protected function targets(): iterable
    {
        yield 'https://shop.test/1';
    }
}

/**
 * Test Spider fixture: it runs {@see TestScraper} (http driver, one try, parses
 * the <title>) over a configurable list of targets and accumulates the results
 * in static buckets so a plain Spider::run() can be asserted afterwards.
 *
 * State is static because run() resolves a FRESH instance through the container;
 * setUp() resets it before every test.
 */
class CollectorSpider extends Spider
{
    protected string $scraper = TestScraper::class;

    /** No inter-request sleep in tests. */
    protected int $delay = 0;

    /** @var array<int, string> The targets to crawl. */
    public static array $targets = [];

    /** @var array<int, string> Targets shouldVisit() must skip. */
    public static array $skip = [];

    /** @var array<int, string> URLs handed to collect(), in order. */
    public static array $collected = [];

    /** @var array<int, mixed> Data payloads handed to collect(). */
    public static array $data = [];

    /** @var array<int, string> URLs routed to onError(). */
    public static array $errored = [];

    /**
     * Reset all static buckets and config between tests.
     */
    public static function reset(): void
    {
        static::$targets = [];
        static::$skip = [];
        static::$collected = [];
        static::$data = [];
        static::$errored = [];
    }

    protected function targets(): iterable
    {
        foreach (static::$targets as $url) {
            yield $url;
        }
    }

    protected function shouldVisit(string $url): bool
    {
        return !in_array($url, static::$skip, true);
    }

    protected function collect(mixed $data, string $url, ScraperResponse $response): void
    {
        static::$collected[] = $url;
        static::$data[] = $data;
    }

    protected function onError(string $url, Throwable $e): void
    {
        static::$errored[] = $url;
    }
}
