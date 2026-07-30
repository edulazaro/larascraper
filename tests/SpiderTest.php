<?php

namespace EduLazaro\Larascraper\Tests;

use Closure;
use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Spider;
use EduLazaro\Larascraper\Support\PendingScraper;
use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Tests\Support\TitleCrawler;
use Illuminate\Support\Facades\Http;

/**
 * Covers the imperative Spider and its fiber-based concurrent pool().
 *
 * The developer writes the whole orchestration in handle(); pool() fans a
 * scraper out over many items, overlapping their network I/O in Http::pool()
 * waves. Everything is driven by Http::fake() on the http driver with delay = 0,
 * so no real browser, network or sleep is involved. Items are the PARAMS for each
 * scraper run (ids, param tuples, or urls), never a url the engine fetches: the
 * unit scraper builds its own url inside handle().
 */
class SpiderTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CollectorSpider::reset();
    }

    /**
     * Find the collected record for a given item (records are order-agnostic
     * under concurrency).
     */
    protected function recordFor(mixed $item): ?array
    {
        foreach (CollectorSpider::$records as $record) {
            if ($record['item'] === $item) {
                return $record;
            }
        }

        return null;
    }

    /**
     * pool() runs the scraper over every item concurrently and hands each result
     * to collect() exactly once, with the correct per-item data.
     */
    public function test_pool_runs_every_item_concurrently_and_collects(): void
    {
        Http::fake([
            'https://shop.test/1' => Http::response('<html><title>one</title></html>', 200),
            'https://shop.test/2' => Http::response('<html><title>two</title></html>', 200),
            'https://shop.test/3' => Http::response('<html><title>three</title></html>', 200),
        ]);

        CollectorSpider::$template = PoolUrlScraper::class;
        CollectorSpider::$items = ['https://shop.test/1', 'https://shop.test/2', 'https://shop.test/3'];
        CollectorSpider::$parallel = 3;

        $returned = CollectorSpider::run();

        $this->assertSame(3, $returned);
        $this->assertCount(3, CollectorSpider::$records);
        $this->assertSame(['title' => 'one'], $this->recordFor('https://shop.test/1')['data']);
        $this->assertSame(['title' => 'two'], $this->recordFor('https://shop.test/2')['data']);
        $this->assertSame(['title' => 'three'], $this->recordFor('https://shop.test/3')['data']);
        Http::assertSentCount(3);
    }

    /**
     * Items are the PARAMS for one scraper run, not urls: ids [1,2,3] where the
     * unit scraper's handle(int $id) builds the url itself.
     */
    public function test_items_are_params_not_urls(): void
    {
        Http::fake([
            'https://items.test/1' => Http::response('<html><title>i-1</title></html>', 200),
            'https://items.test/2' => Http::response('<html><title>i-2</title></html>', 200),
            'https://items.test/3' => Http::response('<html><title>i-3</title></html>', 200),
        ]);

        CollectorSpider::$template = PoolIdScraper::class;
        CollectorSpider::$items = [1, 2, 3];

        CollectorSpider::run();

        $this->assertCount(3, CollectorSpider::$records);
        $this->assertSame(['title' => 'i-1'], $this->recordFor(1)['data']);
        $this->assertSame(['title' => 'i-2'], $this->recordFor(2)['data']);
        $this->assertSame(['title' => 'i-3'], $this->recordFor(3)['data']);

        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->url() === 'https://items.test/1');
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->url() === 'https://items.test/3');
    }

    /**
     * An array item is SPREAD into the handle() arguments: [[2024,'ES'],[2024,'FR']]
     * feed handle(int $year, string $region).
     */
    public function test_items_can_be_param_arrays_spread(): void
    {
        Http::fake([
            'https://reg.test/2024/ES' => Http::response('<html><title>es</title></html>', 200),
            'https://reg.test/2024/FR' => Http::response('<html><title>fr</title></html>', 200),
        ]);

        CollectorSpider::$template = PoolYearRegionScraper::class;
        CollectorSpider::$items = [[2024, 'ES'], [2024, 'FR']];

        CollectorSpider::run();

        $this->assertCount(2, CollectorSpider::$records);
        $this->assertSame(['title' => 'es'], $this->recordFor([2024, 'ES'])['data']);
        $this->assertSame(['title' => 'fr'], $this->recordFor([2024, 'FR'])['data']);

        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->url() === 'https://reg.test/2024/ES');
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->url() === 'https://reg.test/2024/FR');
    }

    /**
     * A configured PendingScraper (Scraper::with(...)) used as the pool template
     * has its configuration applied to every run: a header set with with() rides
     * each pooled request.
     */
    public function test_with_configuration_is_applied_to_pooled_runs(): void
    {
        Http::fake([
            '*' => Http::response('<html><title>ok</title></html>', 200),
        ]);

        CollectorSpider::$template = PoolUrlScraper::with(headers: ['X-Test' => '42']);
        CollectorSpider::$items = ['https://shop.test/1', 'https://shop.test/2'];

        CollectorSpider::run();

        $this->assertCount(2, CollectorSpider::$records);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->hasHeader('X-Test', '42'));
    }

    /**
     * collect can be a method reference ($this->save(...)) and still receives
     * ($data, $item, $response) per item.
     */
    public function test_collect_can_be_a_method_reference(): void
    {
        Http::fake([
            '*' => Http::response('<html><title>ok</title></html>', 200),
        ]);

        CollectorSpider::$template = PoolUrlScraper::class;
        CollectorSpider::$items = ['https://shop.test/1', 'https://shop.test/2'];
        CollectorSpider::$useMethodRef = true;

        CollectorSpider::run();

        $this->assertSame(2, CollectorSpider::$savedCount);
        $this->assertCount(2, CollectorSpider::$records);
        $this->assertSame(['title' => 'ok'], $this->recordFor('https://shop.test/1')['data']);
    }

    /**
     * Per-item isolation: one item that fails at the request level (a 500) is
     * handed to collect() as a failed ScraperResponse (success false, error set,
     * data null), and the other items still succeed. One bad item never aborts
     * the crawl.
     */
    public function test_error_isolation_one_bad_item_does_not_abort_the_crawl(): void
    {
        Http::fake([
            'https://shop.test/bad' => Http::response('nope', 500),
            '*' => Http::response('<html><title>ok</title></html>', 200),
        ]);

        CollectorSpider::$template = PoolUrlScraper::class;
        CollectorSpider::$items = [
            'https://shop.test/1',
            'https://shop.test/bad',
            'https://shop.test/3',
        ];

        CollectorSpider::run();

        $this->assertCount(3, CollectorSpider::$records);

        $bad = $this->recordFor('https://shop.test/bad');
        $this->assertFalse($bad['success']);
        $this->assertNotNull($bad['error']);
        $this->assertNull($bad['data']);

        $this->assertTrue($this->recordFor('https://shop.test/1')['success']);
        $this->assertSame(['title' => 'ok'], $this->recordFor('https://shop.test/1')['data']);
        $this->assertTrue($this->recordFor('https://shop.test/3')['success']);
    }

    /**
     * Per-item isolation also holds for a non-request throw: a scraper whose
     * handle() throws a plain exception yields a failed ScraperResponse, and the
     * other items still succeed.
     */
    public function test_error_isolation_covers_a_thrown_exception(): void
    {
        Http::fake([
            '*' => Http::response('<html><title>ok</title></html>', 200),
        ]);

        CollectorSpider::$template = PoolMaybeThrowScraper::class;
        CollectorSpider::$items = ['https://shop.test/ok', 'https://shop.test/boom'];

        CollectorSpider::run();

        $this->assertTrue($this->recordFor('https://shop.test/ok')['success']);

        $boom = $this->recordFor('https://shop.test/boom');
        $this->assertFalse($boom['success']);
        $this->assertSame('kaboom', $boom['error']);
    }

    /**
     * The shared Session (created in run()) threads a cookie established before
     * pooling into EVERY pooled request: a blocking login at the top of handle()
     * sets SID, and each concurrent request for the same host replays it.
     */
    public function test_shared_session_cookie_rides_every_pooled_request(): void
    {
        Http::fake([
            'https://shop.test/login' => Http::response('ok', 200, [
                'Set-Cookie' => 'SID=abc; Path=/',
            ]),
            '*' => Http::response('<html><title>ok</title></html>', 200),
        ]);

        CollectorSpider::$template = PoolUrlScraper::class;
        CollectorSpider::$items = ['https://shop.test/1', 'https://shop.test/2'];
        CollectorSpider::$before = function (Spider $spider): void {
            // A blocking login at the top of handle() seeds the shared jar.
            PoolLoginScraper::make()->useSession($spider->session)->handleToResponse([]);
        };

        CollectorSpider::run();

        $this->assertSame(['SID' => 'abc'], CollectorSpider::$sessionAfterLogin);

        // Every pooled request (not the login) carried the session cookie.
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => str_contains($r->url(), '/1')
            && in_array('SID=abc', $r->header('Cookie'), true));
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => str_contains($r->url(), '/2')
            && in_array('SID=abc', $r->header('Cookie'), true));
    }

    /**
     * A multi-step unit scraper (two sequential $this->scrape() calls in one
     * handle()) performs BOTH requests through the scheduler, and returns data
     * built from both. Dependent steps within one item stay serial across waves.
     */
    public function test_multi_step_scraper_performs_both_requests(): void
    {
        Http::fake([
            'https://doc.test/a/step1' => Http::response('<html><title>first</title></html>', 200),
            'https://doc.test/a/step2' => Http::response('<html><title>second</title></html>', 200),
        ]);

        CollectorSpider::$template = PoolTwoStepScraper::class;
        CollectorSpider::$items = ['https://doc.test/a'];

        CollectorSpider::run();

        $this->assertCount(1, CollectorSpider::$records);
        $this->assertSame(
            ['a' => 'first', 'b' => 'second'],
            $this->recordFor('https://doc.test/a')['data'],
        );

        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->url() === 'https://doc.test/a/step1');
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->url() === 'https://doc.test/a/step2');
        Http::assertSentCount(2);
    }

    /**
     * The overlap is REAL: three single-step items at concurrency 3 ride a single
     * Http::pool() wave. A blocking fallback would never call runWave() at all, so
     * a wave count of exactly 1 proves the fibers suspended and were pooled.
     */
    public function test_pool_overlaps_items_in_one_wave(): void
    {
        Http::fake([
            '*' => Http::response('<html><title>ok</title></html>', 200),
        ]);

        CollectorSpider::$template = PoolUrlScraper::class;
        CollectorSpider::$items = ['https://shop.test/1', 'https://shop.test/2', 'https://shop.test/3'];
        CollectorSpider::$parallel = 3;
        WaveCountingSpider::$waves = 0;

        WaveCountingSpider::run();

        $this->assertSame(1, WaveCountingSpider::$waves);
        $this->assertCount(3, CollectorSpider::$records);
        Http::assertSentCount(3);
    }

    /**
     * The concurrency cap is enforced: three single-step items at concurrency 2
     * take two waves (two in flight, then the third).
     */
    public function test_pool_respects_the_concurrency_limit(): void
    {
        Http::fake([
            '*' => Http::response('<html><title>ok</title></html>', 200),
        ]);

        CollectorSpider::$template = PoolUrlScraper::class;
        CollectorSpider::$items = ['https://shop.test/1', 'https://shop.test/2', 'https://shop.test/3'];
        CollectorSpider::$parallel = 2;
        WaveCountingSpider::$waves = 0;

        WaveCountingSpider::run();

        $this->assertSame(2, WaveCountingSpider::$waves);
        $this->assertCount(3, CollectorSpider::$records);
    }

    /**
     * A multi-step item's two dependent steps run across two waves (serial within
     * the item), proving the scheduler re-pools a fiber that suspends again.
     */
    public function test_multi_step_item_runs_across_two_waves(): void
    {
        Http::fake([
            'https://doc.test/a/step1' => Http::response('<html><title>first</title></html>', 200),
            'https://doc.test/a/step2' => Http::response('<html><title>second</title></html>', 200),
        ]);

        CollectorSpider::$template = PoolTwoStepScraper::class;
        CollectorSpider::$items = ['https://doc.test/a'];
        WaveCountingSpider::$waves = 0;

        WaveCountingSpider::run();

        $this->assertSame(2, WaveCountingSpider::$waves);
        $this->assertSame(
            ['a' => 'first', 'b' => 'second'],
            $this->recordFor('https://doc.test/a')['data'],
        );
    }

    /**
     * A pooled request retries a retriable status, exactly like the sequential
     * path: a 503 then a 200 for one item recovers (two requests, success). This
     * is the parity the inert per-request ->retry() could not deliver, since a
     * pooled request is async and Laravel ignores its retry loop; retry now lives
     * in the pool() scheduler, which re-issues the slot in the next wave.
     */
    public function test_pool_retries_a_retriable_status_until_it_succeeds(): void
    {
        Http::fakeSequence()
            ->push('busy', 503)
            ->push('<html><title>recovered</title></html>', 200);

        CollectorSpider::$template = PoolRetryScraper::class;
        CollectorSpider::$items = ['https://retry.test/x'];

        CollectorSpider::run();

        $record = $this->recordFor('https://retry.test/x');
        $this->assertTrue($record['success']);
        $this->assertSame(['title' => 'recovered'], $record['data']);
        Http::assertSentCount(2);
    }

    /**
     * Retry parity holds in the OTHER direction too: a non-retriable status (404)
     * fails fast in the pool path with a single request, never burning the retry
     * budget, just as the sequential path breaks out on a non-retriable status.
     */
    public function test_pool_fails_fast_on_a_non_retriable_status(): void
    {
        Http::fake(['*' => Http::response('nope', 404)]);

        CollectorSpider::$template = PoolRetryScraper::class;
        CollectorSpider::$items = ['https://retry.test/x'];

        CollectorSpider::run();

        $record = $this->recordFor('https://retry.test/x');
        $this->assertFalse($record['success']);
        $this->assertNull($record['data']);
        Http::assertSentCount(1);
    }

    /**
     * A persistently retriable status exhausts the per-request attempt budget
     * (tries = 3) and then settles as a failure: exactly three pooled requests,
     * one per wave, mirroring the sequential loop's bounded attempts.
     */
    public function test_pool_gives_up_after_exhausting_retries(): void
    {
        Http::fake(['*' => Http::response('busy', 503)]);

        CollectorSpider::$template = PoolRetryScraper::class;
        CollectorSpider::$items = ['https://retry.test/x'];
        WaveCountingSpider::$waves = 0;

        WaveCountingSpider::run();

        $record = $this->recordFor('https://retry.test/x');
        $this->assertFalse($record['success']);
        $this->assertSame(3, WaveCountingSpider::$waves);
        Http::assertSentCount(3);
    }

    /**
     * BACK-COMPAT: a plain Scraper::run() OUTSIDE any pool still does one blocking
     * fetch, unchanged. The concurrent suspend path only engages inside a pool
     * scheduler while running in a fiber.
     */
    public function test_plain_scraper_run_outside_a_pool_still_blocks(): void
    {
        Http::fake([
            '*' => Http::response('<html><title>solo</title></html>', 200),
        ]);

        $this->assertFalse(Spider::schedulerActive());

        $response = PoolUrlScraper::run('https://shop.test/solo');

        $this->assertTrue($response->success);
        $this->assertSame(['title' => 'solo'], $response->data);
        Http::assertSentCount(1);
        $this->assertFalse(Spider::schedulerActive());
    }
}

/**
 * Configurable Spider fixture: it fans {@see PoolUrlScraper} (or another template)
 * out over a list of items via pool(), and records every ($data, $item, $success)
 * collect() receives in static buckets so a plain Spider::run() can be asserted
 * afterwards.
 *
 * State is static because run() resolves a FRESH instance through the container;
 * setUp() resets it before every test.
 */
class CollectorSpider extends Spider
{
    protected int $delay = 0;

    /** @var int Runs in flight; overridable per test. */
    public static int $parallel = 5;

    /** @var string|PendingScraper|null The scraper template pooled over the items. */
    public static string|PendingScraper|null $template = null;

    /** @var array<int, mixed> The items (per-run params) to crawl. */
    public static array $items = [];

    /** @var array<int, array{data: mixed, item: mixed, success: bool, error: ?string}> Every collect() call. */
    public static array $records = [];

    /** @var bool Collect via the $this->save(...) method reference instead of a closure. */
    public static bool $useMethodRef = false;

    /** @var int Times save() ran (proves the method-reference collector fired). */
    public static int $savedCount = 0;

    /** @var Closure|null Optional hook run at the top of handle(), before pooling. */
    public static ?Closure $before = null;

    /** @var array<string, string>|null The session cookies for shop.test after $before ran. */
    public static ?array $sessionAfterLogin = null;

    /**
     * Reset all static buckets and config between tests.
     */
    public static function reset(): void
    {
        static::$parallel = 5;
        static::$template = null;
        static::$items = [];
        static::$records = [];
        static::$useMethodRef = false;
        static::$savedCount = 0;
        static::$before = null;
        static::$sessionAfterLogin = null;
    }

    public function handle(): mixed
    {
        if (static::$before !== null) {
            (static::$before)($this);
            static::$sessionAfterLogin = $this->session?->cookiesFor('shop.test');
        }

        $collect = static::$useMethodRef
            ? $this->save(...)
            : function (mixed $data, mixed $item, ScraperResponse $response): void {
                static::$records[] = [
                    'data' => $data,
                    'item' => $item,
                    'success' => $response->success,
                    'error' => $response->error,
                ];
            };

        $this->pool(static::$items, static::$template, $collect, static::$parallel);

        return count(static::$records);
    }

    /**
     * A method-reference collector target.
     */
    protected function save(mixed $data, mixed $item, ScraperResponse $response): void
    {
        static::$savedCount++;
        static::$records[] = [
            'data' => $data,
            'item' => $item,
            'success' => $response->success,
            'error' => $response->error,
        ];
    }
}

/**
 * CollectorSpider that counts how many Http::pool() waves the scheduler runs, to
 * prove pool() truly overlaps fetches (a blocking fallback would run zero waves)
 * and honours the concurrency cap. It reuses all of CollectorSpider's static
 * config and buckets.
 */
class WaveCountingSpider extends CollectorSpider
{
    /** @var int Number of Http::pool() waves executed. */
    public static int $waves = 0;

    protected function runWave(array $specs): array
    {
        static::$waves++;

        return parent::runWave($specs);
    }
}

/**
 * Unit scraper whose param IS the url: it fetches it and returns the parsed
 * <title>. Pins the http driver so Http::fake() drives it, one try, no retry
 * sleep.
 */
class PoolUrlScraper extends Scraper
{
    protected string $driver = 'http';
    protected int $tries = 1;
    protected int $retryDelay = 0;

    protected function handle(string $url): ScraperResponse
    {
        return $this->scrape($url)->crawl(TitleCrawler::class)->run();
    }
}

/**
 * Unit scraper whose param is an id it turns into a url. Proves items are params.
 */
class PoolIdScraper extends Scraper
{
    protected string $driver = 'http';
    protected int $tries = 1;
    protected int $retryDelay = 0;

    protected function handle(int $id): ScraperResponse
    {
        return $this->scrape("https://items.test/{$id}")->crawl(TitleCrawler::class)->run();
    }
}

/**
 * Unit scraper taking two params spread from an array item.
 */
class PoolYearRegionScraper extends Scraper
{
    protected string $driver = 'http';
    protected int $tries = 1;
    protected int $retryDelay = 0;

    protected function handle(int $year, string $region): ScraperResponse
    {
        return $this->scrape("https://reg.test/{$year}/{$region}")->crawl(TitleCrawler::class)->run();
    }
}

/**
 * Multi-step unit scraper: two sequential fetches in one handle(), returning data
 * built from both. Proves dependent steps stay serial while overlapping across
 * items in the scheduler.
 */
class PoolTwoStepScraper extends Scraper
{
    protected string $driver = 'http';
    protected int $tries = 1;
    protected int $retryDelay = 0;

    protected function handle(string $base): array
    {
        $first = $this->scrape("{$base}/step1")->crawl(TitleCrawler::class)->run();
        $second = $this->scrape("{$base}/step2")->crawl(TitleCrawler::class)->run();

        return [
            'a' => $first->data['title'],
            'b' => $second->data['title'],
        ];
    }
}

/**
 * Unit scraper that throws a plain exception for one url after fetching, to prove
 * per-item isolation covers any Throwable, not only a RequestException.
 */
class PoolMaybeThrowScraper extends Scraper
{
    protected string $driver = 'http';
    protected int $tries = 1;
    protected int $retryDelay = 0;

    protected function handle(string $url): ScraperResponse
    {
        $response = $this->scrape($url)->crawl(TitleCrawler::class)->run();

        if (str_contains($url, 'boom')) {
            throw new \RuntimeException('kaboom');
        }

        return $response;
    }
}

/**
 * Unit scraper whose param IS the url, with a real retry budget (tries = 3, no
 * sleep in tests). Proves the pool() scheduler retries retriable statuses and
 * fails fast on non-retriable ones, matching the sequential retry loop.
 */
class PoolRetryScraper extends Scraper
{
    protected string $driver = 'http';
    protected int $tries = 3;
    protected int $retryDelay = 0;

    protected function handle(string $url): ScraperResponse
    {
        return $this->scrape($url)->crawl(TitleCrawler::class)->run();
    }
}

/**
 * A blocking login scraper: it establishes a session cookie the pool then threads
 * into every request.
 */
class PoolLoginScraper extends Scraper
{
    protected string $driver = 'http';
    protected int $tries = 1;
    protected int $retryDelay = 0;

    protected function handle(): ScraperResponse
    {
        return $this->scrape('https://shop.test/login')->run();
    }
}
