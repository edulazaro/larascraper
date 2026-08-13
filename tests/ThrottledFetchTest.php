<?php

namespace EduLazaro\Larascraper\Tests;

use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\FetchBuilder;
use EduLazaro\Larascraper\Support\Throttle;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Covers where the throttle meets the proxy pool: the key a scraper declares
 * reaching the fetch, and a refused address being left out of the draw.
 *
 * The Throttle's own behaviour lives in ThrottleTest; what is checked here is
 * only that FetchBuilder asks it before choosing an exit.
 */
class ThrottledFetchTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['larascraper.throttle' => [
            'shop.listing' => ['interval' => 0, 'lock_base' => 120, 'lock_max' => 600],
        ]]);
    }

    /** @return array{url: ?string, user: ?string, pass: ?string} */
    private function resolveFor(Scraper $scraper, ?Throttle $throttle = null): array
    {
        $builder = $scraper->scrape('https://shop.test/x');

        $method = new ReflectionMethod(FetchBuilder::class, 'resolveProxy');
        $method->setAccessible(true);

        return $method->invoke($builder, $throttle);
    }

    private function hasHealthyProxy(Scraper $scraper, Throttle $throttle): bool
    {
        $builder = $scraper->scrape('https://shop.test/x');

        $method = new ReflectionMethod(FetchBuilder::class, 'hasHealthyProxy');
        $method->setAccessible(true);

        return $method->invoke($builder, $throttle);
    }

    public function test_the_scrapers_key_reaches_the_fetch(): void
    {
        $builder = (new KeyedScraper())->scrape('https://shop.test/x');

        $property = new ReflectionProperty(FetchBuilder::class, 'throttleKey');
        $property->setAccessible(true);

        $this->assertSame('shop.listing', $property->getValue($builder));
    }

    public function test_a_scraper_without_a_key_declares_none(): void
    {
        $builder = (new UnkeyedScraper())->scrape('https://shop.test/x');

        $property = new ReflectionProperty(FetchBuilder::class, 'throttleKey');
        $property->setAccessible(true);

        // Null, not the host: the fallback to the host happens at fetch time,
        // where the URL is known.
        $this->assertNull($property->getValue($builder));
    }

    public function test_a_refused_proxy_is_left_out_of_the_draw(): void
    {
        config(['larascraper.proxies' => ['203.0.113.10:8080', '203.0.113.11:8080']]);

        $throttle = new Throttle('shop.listing');
        $throttle->lockOut('203.0.113.10:8080');

        // Twenty draws over a two-entry pool: were the locked one still in it,
        // missing it every time would be a one-in-a-million accident.
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame('203.0.113.11:8080', $this->resolveFor(new UnkeyedScraper(), $throttle)['url']);
        }
    }

    /**
     * A stale lockout is a better bet than not trying at all: with nowhere left
     * to go the pool comes back whole rather than empty, and the caller decides
     * whether the attempt is worth making.
     */
    public function test_the_whole_pool_comes_back_when_every_proxy_is_locked_out(): void
    {
        config(['larascraper.proxies' => ['203.0.113.10:8080', '203.0.113.11:8080']]);

        $throttle = new Throttle('shop.listing');
        $throttle->lockOut('203.0.113.10:8080');
        $throttle->lockOut('203.0.113.11:8080');

        $this->assertContains(
            $this->resolveFor(new UnkeyedScraper(), $throttle)['url'],
            ['203.0.113.10:8080', '203.0.113.11:8080'],
        );
    }

    public function test_credentials_ride_along_with_the_chosen_exit(): void
    {
        config(['larascraper.proxies' => [
            '203.0.113.10:8080',
            ['url' => '203.0.113.11:8080', 'user' => 'user', 'pass' => 'secret'],
        ]]);

        $throttle = new Throttle('shop.listing');
        $throttle->lockOut('203.0.113.10:8080');

        $parts = $this->resolveFor(new UnkeyedScraper(), $throttle);

        $this->assertSame('203.0.113.11:8080', $parts['url']);
        $this->assertSame('user', $parts['user']);
        $this->assertSame('secret', $parts['pass']);
    }

    public function test_an_explicit_proxy_ignores_the_lockout(): void
    {
        $throttle = new Throttle('shop.listing');
        $throttle->lockOut('203.0.113.99:9090');

        // A pinned address is a decision, not a suggestion: honouring the lockout
        // here would silently send the request somewhere it was never meant to go.
        $this->assertSame('203.0.113.99:9090', $this->resolveFor(new PinnedScraper(), $throttle)['url']);
    }

    public function test_a_free_proxy_makes_a_retry_worth_it(): void
    {
        config(['larascraper.proxies' => ['203.0.113.10:8080', '203.0.113.11:8080']]);

        $throttle = new Throttle('shop.listing');
        $throttle->lockOut('203.0.113.10:8080');

        $this->assertTrue($this->hasHealthyProxy(new UnkeyedScraper(), $throttle));
    }

    public function test_no_free_proxy_means_there_is_nothing_left_to_try(): void
    {
        config(['larascraper.proxies' => ['203.0.113.10:8080']]);

        $throttle = new Throttle('shop.listing');
        $throttle->lockOut('203.0.113.10:8080');

        $this->assertFalse($this->hasHealthyProxy(new UnkeyedScraper(), $throttle));
    }

    /**
     * With no pool at all every request goes out the same way, so a refusal
     * leaves no alternative: retrying would only repeat it.
     */
    public function test_an_empty_pool_offers_nothing_to_fall_back_on(): void
    {
        config(['larascraper.proxies' => []]);

        $this->assertFalse($this->hasHealthyProxy(new UnkeyedScraper(), new Throttle('shop.listing')));
    }

    public function test_the_direct_exit_can_be_the_healthy_one(): void
    {
        config(['larascraper.proxies' => ['203.0.113.10:8080', ['url' => null]]]);

        $throttle = new Throttle('shop.listing');
        $throttle->lockOut('203.0.113.10:8080');

        $this->assertTrue($this->hasHealthyProxy(new UnkeyedScraper(), $throttle));
        $this->assertNull($this->resolveFor(new UnkeyedScraper(), $throttle)['url']);
    }
}

class KeyedScraper extends Scraper
{
    protected string $driver = 'http';

    protected ?string $throttleKey = 'shop.listing';
}

class UnkeyedScraper extends Scraper
{
    protected string $driver = 'http';
}

class PinnedScraper extends Scraper
{
    protected string $driver = 'http';

    protected ?string $proxy = '203.0.113.99:9090';
}
