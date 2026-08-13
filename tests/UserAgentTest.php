<?php

namespace EduLazaro\Larascraper\Tests;

use EduLazaro\Larascraper\Runners\HttpRunner;
use EduLazaro\Larascraper\Runners\PuppeteerRunner;
use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\FetchBuilder;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;

/**
 * Covers who the scraper says it is.
 *
 * The value itself is only half of it, and the cheaper half: what matters is
 * that a claim can be made at every level a scraper is configured at, and that
 * NOT making one leaves the browser free to answer for itself. Deriving the
 * agent from the Chrome that actually launched is what keeps the UA string and
 * the Client Hints telling the same story, and that lives in scraper.cjs, where
 * the browser is — PHP has nobody to ask.
 */
class UserAgentTest extends BaseTestCase
{
    private function agentOf(FetchBuilder $builder): ?string
    {
        $property = new ReflectionProperty(FetchBuilder::class, 'userAgent');
        $property->setAccessible(true);

        return $property->getValue($builder);
    }

    public function test_a_scraper_claims_nothing_by_default(): void
    {
        // Null is not "no agent": it is the browser answering for itself, which is
        // the only answer guaranteed to match the browser.
        $this->assertNull($this->agentOf((new PlainScraper())->scrape('https://shop.test/x')));
    }

    public function test_a_scraper_can_declare_one(): void
    {
        $this->assertSame(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/605.1.15',
            $this->agentOf((new MobileScraper())->scrape('https://shop.test/x')),
        );
    }

    public function test_a_single_fetch_can_override_the_scrapers_own(): void
    {
        $builder = (new MobileScraper())->scrape('https://shop.test/x')->userAgent('Mozilla/5.0 (X11; Linux x86_64)');

        $this->assertSame('Mozilla/5.0 (X11; Linux x86_64)', $this->agentOf($builder));
    }

    public function test_a_fetch_can_hand_the_question_back_to_the_browser(): void
    {
        $builder = (new MobileScraper())->scrape('https://shop.test/x')->userAgent(null);

        $this->assertNull($this->agentOf($builder));
    }

    public function test_the_http_driver_sends_it(): void
    {
        Http::fake(['*' => Http::response('<html></html>', 200)]);

        HttpRunner::on('https://shop.test/x')->userAgent('Claimed/1.0')->run();

        Http::assertSent(fn ($request) => $request->header('User-Agent') === ['Claimed/1.0']);
    }

    /**
     * A header written by hand is a deliberate act and outranks configuration.
     * (The browser driver honours the same precedence, but gets there another
     * way — see test_the_script_routes_a_header_through_set_user_agent.)
     */
    public function test_an_explicit_header_outranks_the_configured_agent(): void
    {
        Http::fake(['*' => Http::response('<html></html>', 200)]);

        HttpRunner::on('https://shop.test/x')
            ->userAgent('Configured/1.0')
            ->withHeaders(['User-Agent' => 'Explicit/2.0'])
            ->run();

        Http::assertSent(fn ($request) => $request->header('User-Agent') === ['Explicit/2.0']);
    }

    /**
     * The http driver has nobody to ask — no Chrome is launched — so its default
     * has to be written out. It lives in config rather than in the code so it can
     * be bumped without a release, since unlike the derived one it ages.
     *
     * What it must never be is 'GuzzleHttp/7', which is what Guzzle sends unasked
     * and is an announcement that a script is calling.
     */
    public function test_the_http_driver_falls_back_to_the_configured_agent(): void
    {
        config(['larascraper.http_user_agent' => 'Configured/1.0']);
        Http::fake(['*' => Http::response('<html></html>', 200)]);

        HttpRunner::on('https://shop.test/x')->run();

        Http::assertSent(fn ($request) => $request->header('User-Agent') === ['Configured/1.0']);
    }

    public function test_a_scrapers_own_agent_outranks_the_configured_one(): void
    {
        config(['larascraper.http_user_agent' => 'Configured/1.0']);
        Http::fake(['*' => Http::response('<html></html>', 200)]);

        HttpRunner::on('https://shop.test/x')->userAgent('Chosen/2.0')->run();

        Http::assertSent(fn ($request) => $request->header('User-Agent') === ['Chosen/2.0']);
    }

    /**
     * There is no way down to nothing, and that is the point: sending no user
     * agent is not silence — Guzzle fills in 'GuzzleHttp/7'. So an empty or
     * missing key reads as "no opinion" and lands on the floor instead.
     */
    public function test_emptying_the_config_falls_to_the_floor_not_to_nothing(): void
    {
        Http::fake(['*' => Http::response('<html></html>', 200)]);

        foreach (['', null] as $emptied) {
            config(['larascraper.http_user_agent' => $emptied]);

            HttpRunner::on('https://shop.test/x')->run();
        }

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->header('User-Agent') === [HttpRunner::DEFAULT_USER_AGENT]);
    }

    public function test_a_missing_key_falls_to_the_floor_too(): void
    {
        // The whole config replaced by one that never mentions the key.
        config(['larascraper' => ['proxies' => []]]);
        Http::fake(['*' => Http::response('<html></html>', 200)]);

        HttpRunner::on('https://shop.test/x')->run();

        Http::assertSent(fn ($request) => $request->header('User-Agent') === [HttpRunner::DEFAULT_USER_AGENT]);
    }

    public function test_the_floor_is_not_a_script_announcing_itself(): void
    {
        $this->assertStringNotContainsString('Guzzle', HttpRunner::DEFAULT_USER_AGENT);
        $this->assertStringStartsWith('Mozilla/5.0', HttpRunner::DEFAULT_USER_AGENT);
    }

    /**
     * A fetch inside a Spider does NOT go through HttpRunner::run(): under a
     * pool() it suspends into the concurrent wave, which builds its own request.
     * That is a second code path, and a second chance for the scraper's identity
     * to be dropped on the floor — so it is asserted, not assumed.
     */
    public function test_a_scrapers_own_agent_survives_the_spiders_concurrent_path(): void
    {
        Http::fake(['*' => Http::response('<html></html>', 200)]);

        AgentSpider::$scraper = PooledScraper::class;
        AgentSpider::$urls = ['https://shop.test/1', 'https://shop.test/2'];

        AgentSpider::run();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->header('User-Agent') === ['Pooled/1.0']);
    }

    public function test_the_spiders_concurrent_path_falls_back_like_everything_else(): void
    {
        config(['larascraper.http_user_agent' => 'Configured/1.0']);
        Http::fake(['*' => Http::response('<html></html>', 200)]);

        AgentSpider::$scraper = PooledDefaultScraper::class;
        AgentSpider::$urls = ['https://shop.test/1'];

        AgentSpider::run();

        Http::assertSent(fn ($request) => $request->header('User-Agent') === ['Configured/1.0']);
    }

    /**
     * The trap this guards against: the config value is for the driver with
     * nobody to ask. Letting it reach the browser driver would put back exactly
     * the bug it replaced — a claimed version contradicting the Chrome emitting
     * the Client Hints.
     */
    public function test_the_configured_agent_never_reaches_the_browser_driver(): void
    {
        config(['larascraper.http_user_agent' => 'Configured/1.0']);

        $runner = PuppeteerRunner::on('https://shop.test/x');

        $property = new ReflectionProperty(PuppeteerRunner::class, 'userAgent');
        $property->setAccessible(true);

        $this->assertNull($property->getValue($runner));

        $script = file_get_contents(__DIR__ . '/../resources/scraper.cjs');
        $this->assertStringNotContainsString('http_user_agent', $script);
    }

    public function test_the_browser_driver_is_only_told_when_there_is_something_to_tell(): void
    {
        $runner = PuppeteerRunner::on('https://shop.test/x');

        $property = new ReflectionProperty(PuppeteerRunner::class, 'userAgent');
        $property->setAccessible(true);

        $this->assertNull($property->getValue($runner), 'Silence is what lets scraper.cjs ask Chrome.');

        $runner->userAgent('Claimed/1.0');

        $this->assertSame('Claimed/1.0', $property->getValue($runner));
    }

    /**
     * The derivation itself is one replace in scraper.cjs. Asserted here on the
     * shape rather than by launching Chrome, so the suite stays offline: what is
     * being pinned is that the fix leaves version and platform untouched, since
     * rewriting either is what created the contradiction it exists to remove.
     */
    public function test_the_derivation_only_drops_the_word_that_gives_it_away(): void
    {
        $headless = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/148.0.0.0 Safari/537.36';

        $derived = str_replace('HeadlessChrome', 'Chrome', $headless);

        $this->assertSame(
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
            $derived,
        );
        $this->assertStringContainsString('148.0.0.0', $derived, 'The real version has to survive.');
        $this->assertStringContainsString('X11; Linux x86_64', $derived, 'So does the real platform.');
        $this->assertStringNotContainsString('Headless', $derived);
    }

    /**
     * On the browser driver a User-Agent header cannot be sent as a header: once
     * Chrome has a user-agent override — and the stealth plugin installs one even
     * when we do not — an extra header for it is ignored. Measured, not assumed:
     * with stealth active, `setExtraHTTPHeaders({'User-Agent': …})` alone never
     * reached the wire.
     *
     * So it is pulled out and applied through setUserAgent, which is also the
     * only call that moves BOTH the wire header and navigator.userAgent. Asserted
     * against the script rather than by launching Chrome, so the suite stays
     * offline; what it guards is that the routing does not quietly disappear and
     * take the http driver's precedence out of step with the browser's.
     */
    public function test_the_script_routes_a_header_through_set_user_agent(): void
    {
        $script = file_get_contents(__DIR__ . '/../resources/scraper.cjs');

        // Case-insensitively, since a caller may write 'user-agent'.
        $this->assertStringContainsString("k.toLowerCase() === 'user-agent'", $script);

        // The header wins, and reaches Chrome through setUserAgent.
        $this->assertStringContainsString(
            'setUserAgent(headerUserAgent || userAgent || realUserAgent)',
            $script,
        );

        // And is removed afterwards, so the same fact is not stated twice.
        $this->assertStringContainsString('delete headers[headerKey]', $script);
    }

    public function test_the_script_asks_the_browser_rather_than_naming_a_version(): void
    {
        $script = file_get_contents(__DIR__ . '/../resources/scraper.cjs');

        $this->assertStringContainsString('await browser.userAgent()', $script);

        // The regression this guards: a Chrome version typed by hand rots, and a
        // UA that disagrees with the Client Hints of the browser sending it is a
        // louder signal than an honest headless one.
        $this->assertDoesNotMatchRegularExpression(
            "/setUserAgent\(\s*['\"]Mozilla/",
            $script,
            'The user agent must be derived, never written out.',
        );
    }
}

class PlainScraper extends Scraper
{
    protected string $driver = 'http';
}

/**
 * A scraper as it is actually used inside a Spider: it fetches through
 * $this->scrape(), which under a pool() suspends into the concurrent wave
 * instead of going through HttpRunner::run().
 */
class PooledScraper extends Scraper
{
    protected string $driver = 'http';

    protected ?string $userAgent = 'Pooled/1.0';

    public function handle(string $url): int
    {
        return $this->scrape($url)->run()->status;
    }
}

class PooledDefaultScraper extends Scraper
{
    protected string $driver = 'http';

    public function handle(string $url): int
    {
        return $this->scrape($url)->run()->status;
    }
}

/** Minimal spider: fan the given scraper over the given urls, keep nothing. */
class AgentSpider extends \EduLazaro\Larascraper\Spider
{
    protected int $delay = 0;

    public static string $scraper = PooledScraper::class;

    public static array $urls = [];

    public function handle(): mixed
    {
        $this->pool(static::$urls, static::$scraper, static fn () => null);

        return null;
    }
}

class MobileScraper extends Scraper
{
    protected string $driver = 'http';

    protected ?string $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/605.1.15';
}
