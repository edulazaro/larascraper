<?php

namespace EduLazaro\Larascraper\Tests;

use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\RequestResponse;
use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Tests\Support\BinaryScraper;
use EduLazaro\Larascraper\Tests\Support\TestScraper;
use EduLazaro\Larascraper\Tests\Support\TitleCrawler;
use Illuminate\Support\Facades\Http;

/**
 * Covers the two external entry points of a scraper on the `http` driver
 * (driven by Http::fake): the static run() with its three param-mapping forms,
 * the with()->run() chain that injects orchestration properties before the run,
 * the container-backed make() path for constructor dependencies, the population
 * of $this->request, and the bare FetchBuilder::run()
 * terminal that returns the raw html as data.
 */
class ScraperTest extends BaseTestCase
{
    /**
     * run() with a single positional argument maps to handle(string $url).
     */
    public function test_run_maps_a_positional_argument_to_handle(): void
    {
        Http::fake([
            '*' => Http::response('<html><head><title>Bike 4</title></head></html>', 200),
        ]);

        $response = TestScraper::run('https://shop.test/bikes/4');

        $this->assertInstanceOf(ScraperResponse::class, $response);
        $this->assertTrue($response->success);
        $this->assertSame(['title' => 'Bike 4'], $response->data);
    }

    /**
     * run() with a named argument maps to the handle() parameter by name.
     */
    public function test_run_maps_a_named_argument_to_handle(): void
    {
        Http::fake([
            '*' => Http::response('<html><head><title>Bike 4</title></head></html>', 200),
        ]);

        $response = TestScraper::run(url: 'https://shop.test/bikes/4');

        $this->assertTrue($response->success);
        $this->assertSame(['title' => 'Bike 4'], $response->data);
    }

    /**
     * run() with a single associative array is treated as a named-argument bag.
     */
    public function test_run_maps_an_associative_array_to_handle(): void
    {
        Http::fake([
            '*' => Http::response('<html><head><title>Bike 4</title></head></html>', 200),
        ]);

        $response = TestScraper::run(['url' => 'https://shop.test/bikes/4']);

        $this->assertTrue($response->success);
        $this->assertSame(['title' => 'Bike 4'], $response->data);
    }

    /**
     * A single associative array to a handle() whose one parameter is array-typed
     * is forwarded WHOLE as that argument (the "attribute bag"), not name-mapped.
     */
    public function test_a_single_array_is_forwarded_whole_to_an_array_typed_handle(): void
    {
        $response = BagScraper::run(['a' => 1, 'b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $response->data);
    }

    /**
     * Two terminal reads off the SAME builder share one memoized fetch, so the
     * network is hit exactly once (FetchBuilder::$fetched).
     */
    public function test_two_terminal_reads_on_one_builder_share_a_single_fetch(): void
    {
        Http::fake(['*' => Http::response('<html><body><h1>A</h1><p class="x">B</p></body></html>', 200)]);

        $page = TestScraper::make()->scrape('https://shop.test/x');
        $page->crawl('h1')->text();
        $page->crawl('.x')->text();

        Http::assertSentCount(1);
    }

    /**
     * Proxy-auth credentials (proxyUser/proxyPass) reach the request as basic auth.
     */
    public function test_proxy_auth_credentials_reach_the_request(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        AuthScraper::run('https://shop.test/x');

        Http::assertSent(function (\Illuminate\Http\Client\Request $r) {
            $auth = $r->header('Authorization');
            return ! empty($auth) && str_starts_with($auth[0], 'Basic ');
        });
    }

    /**
     * with(tries: 2) injects the protected $tries property before run() fires.
     */
    public function test_with_injects_a_protected_property_before_run(): void
    {
        // Baseline: the fixture pins tries = 1.
        $baseline = TriesScraper::run('https://shop.test/x');
        $this->assertTrue($baseline->success);
        $this->assertSame(['tries' => 1], $baseline->data);

        // with(tries: 2) must reach the run() with the injected value.
        $configured = TriesScraper::with(tries: 2)->run('https://shop.test/x');
        $this->assertTrue($configured->success);
        $this->assertSame(['tries' => 2], $configured->data);
    }

    /**
     * make() resolves the scraper through the container, so constructor
     * dependencies (here a container-bound value) are injected.
     */
    public function test_make_resolves_constructor_dependencies_via_the_container(): void
    {
        Http::fake([
            '*' => Http::response('<html><head><title>Bike 4</title></head></html>', 200),
        ]);

        $this->app->instance(GreeterDependency::class, new GreeterDependency('container-resolved'));

        $response = DependencyScraper::run('https://shop.test/bikes/4');

        $this->assertTrue($response->success);
        $this->assertSame(
            ['dependency' => 'container-resolved', 'title' => 'Bike 4'],
            $response->data,
        );
    }

    /**
     * After a fetch, the HTTP-layer facts (status, contentType, cookies) live on
     * the scraper instance as $this->request (a RequestResponse). They are NOT on
     * the returned ScraperResponse, which is data/success/error only.
     */
    public function test_the_request_is_populated_with_wire_facts(): void
    {
        Http::fake([
            '*' => Http::response('<html><head><title>Bike 4</title></head></html>', 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Set-Cookie' => 'SESSION=abc123; Path=/; HttpOnly',
            ]),
        ]);

        $scraper = TestScraper::make();
        $scraper->callHandle(['url' => 'https://shop.test/bikes/4']);

        // The wire facts live on the instance as $this->request.
        $this->assertInstanceOf(RequestResponse::class, $scraper->request);
        $this->assertSame(200, $scraper->request->status);
        $this->assertSame('text/html; charset=utf-8', $scraper->request->contentType);
        $this->assertSame('abc123', $scraper->request->cookies['SESSION'] ?? null);
    }

    /**
     * A handle() that returns $this->scrape($url)->run() (the bare FetchBuilder
     * terminal, no crawler) yields the raw html as the ScraperResponse data.
     */
    public function test_bare_fetch_builder_run_returns_the_html_as_data(): void
    {
        $html = '<html><head><title>Bike 4</title></head><body>hi</body></html>';

        Http::fake([
            '*' => Http::response($html, 200),
        ]);

        $response = BinaryScraper::run('https://shop.test/bikes/4');

        $this->assertTrue($response->success);
        $this->assertSame($html, $response->data);
    }

    /**
     * A raw scalar returned by handle() is wrapped into ScraperResponse::$data
     * with success = true; handle() no longer has to build the response itself.
     */
    public function test_a_raw_return_is_wrapped_as_data(): void
    {
        Http::fake(['*' => Http::response('<html></html>', 200)]);

        $response = RawScraper::run('https://shop.test/x');

        $this->assertInstanceOf(ScraperResponse::class, $response);
        $this->assertTrue($response->success);
        $this->assertSame('hello', $response->data);
    }

    /**
     * $this->fail('code') returns a failed ScraperResponse from inside handle(),
     * without throwing: success = false, error = the code.
     */
    public function test_fail_returns_a_failed_response(): void
    {
        Http::fake(['*' => Http::response('<html></html>', 200)]);

        $response = FailScraper::run('https://shop.test/x');

        $this->assertInstanceOf(ScraperResponse::class, $response);
        $this->assertFalse($response->success);
        $this->assertSame('no_results', $response->error);
        $this->assertNull($response->data);
    }
}

/**
 * A trivial constructor dependency used to prove make() routes through the
 * container. Its value is asserted in the scraper output.
 */
class GreeterDependency
{
    public function __construct(public string $value = 'default')
    {
    }
}

/**
 * Scraper with a constructor dependency, resolved by make()/app().
 */
class DependencyScraper extends Scraper
{
    /** Force the HTTP runner so Http::fake() drives the request in tests. */
    protected string $driver = 'http';

    /** One attempt: no retry loop. */
    protected int $tries = 1;

    public function __construct(protected GreeterDependency $greeter)
    {
    }

    /**
     * Fetch and parse the title, then fold in the injected dependency value.
     */
    protected function handle(string $url): array
    {
        $inner = $this->scrape($url)->crawl(TitleCrawler::class)->run();

        // Raw array return: run() wraps it into ScraperResponse(data: ...).
        return [
            'dependency' => $this->greeter->value,
            'title' => $inner->data['title'] ?? null,
        ];
    }
}

/**
 * Scraper that exposes its own $tries via the response, so with(tries: 2) can be
 * asserted end to end. It does not fetch.
 */
class TriesScraper extends Scraper
{
    /** Force the HTTP runner so Http::fake() drives the request in tests. */
    protected string $driver = 'http';

    /** Pinned to one attempt; with(tries: 2) overrides it. */
    protected int $tries = 1;

    /**
     * Return the current $tries value as the scrape data.
     */
    protected function handle(string $url): ScraperResponse
    {
        return new ScraperResponse(data: ['tries' => $this->tries], success: true);
    }
}

/**
 * Scraper whose handle() returns a raw scalar, to prove run() wraps it.
 */
class RawScraper extends Scraper
{
    /** Force the HTTP runner so Http::fake() drives the request in tests. */
    protected string $driver = 'http';

    /** One attempt: no retry loop. */
    protected int $tries = 1;

    protected function handle(string $url): string
    {
        return 'hello';
    }
}

/**
 * Scraper whose handle() marks the scrape failed via $this->fail() (no throw).
 */
class FailScraper extends Scraper
{
    /** Force the HTTP runner so Http::fake() drives the request in tests. */
    protected string $driver = 'http';

    /** One attempt: no retry loop. */
    protected int $tries = 1;

    protected function handle(string $url): ScraperResponse
    {
        $this->scrape($url)->run();

        return $this->fail('no_results');
    }
}

/**
 * Scraper whose handle() takes a single array parameter, to exercise the
 * callHandle "attribute bag" branch (the whole array arrives as one argument).
 */
class BagScraper extends Scraper
{
    protected function handle(array $bag): array
    {
        return $bag;
    }
}

/**
 * Scraper carrying proxy-auth credentials, to exercise the guarded
 * runner->authenticate() wiring (FetchBuilder + HttpRunner basic auth).
 */
class AuthScraper extends Scraper
{
    /** Force the HTTP runner so Http::fake() drives the request in tests. */
    protected string $driver = 'http';

    /** One attempt: no retry loop. */
    protected int $tries = 1;

    protected ?string $proxyUser = 'user';
    protected ?string $proxyPass = 'pass';

    protected function handle(string $url): ScraperResponse
    {
        return $this->scrape($url)->run();
    }
}
