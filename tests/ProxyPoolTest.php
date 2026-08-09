<?php

namespace EduLazaro\Larascraper\Tests;

use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\FetchBuilder;
use ReflectionMethod;

/**
 * Covers config('larascraper.proxies'): the two spellings of an entry, and the
 * precedence between what a scraper sets explicitly and what the pool offers.
 */
class ProxyPoolTest extends BaseTestCase
{
    /** @return array{url: ?string, user: ?string, pass: ?string} */
    private function resolveFor(Scraper $scraper): array
    {
        $builder = $scraper->scrape('https://shop.test/x');

        $method = new ReflectionMethod(FetchBuilder::class, 'resolveProxy');
        $method->setAccessible(true);

        return $method->invoke($builder);
    }

    public function test_a_plain_address_is_used_as_is(): void
    {
        $this->assertSame(
            ['url' => '203.0.113.10:8080', 'user' => null, 'pass' => null],
            FetchBuilder::normalizeProxy('203.0.113.10:8080'),
        );
    }

    /**
     * Chrome ignores credentials inside --proxy-server, so they must come out of
     * the URL and travel separately for page.authenticate().
     */
    public function test_inline_credentials_are_split_out_of_the_url(): void
    {
        $this->assertSame(
            ['url' => 'http://203.0.113.11:8080', 'user' => 'user', 'pass' => 'secret'],
            FetchBuilder::normalizeProxy('http://user:secret@203.0.113.11:8080'),
        );
    }

    public function test_a_schemeless_address_does_not_gain_a_scheme(): void
    {
        $this->assertSame(
            '203.0.113.12:8080',
            FetchBuilder::normalizeProxy('user:secret@203.0.113.12:8080')['url'],
        );
    }

    public function test_percent_encoded_credentials_are_decoded(): void
    {
        $parts = FetchBuilder::normalizeProxy('http://u%40b:p%3Aw@203.0.113.13:8080');

        $this->assertSame('u@b', $parts['user']);
        $this->assertSame('p:w', $parts['pass']);
    }

    public function test_the_array_spelling_is_accepted(): void
    {
        $this->assertSame(
            ['url' => '203.0.113.14:8080', 'user' => 'user', 'pass' => 'secret'],
            FetchBuilder::normalizeProxy(['url' => '203.0.113.14:8080', 'user' => 'user', 'pass' => 'secret']),
        );
    }

    public function test_a_configured_proxy_is_used_when_the_scraper_sets_none(): void
    {
        config(['larascraper.proxies' => ['203.0.113.20:8080']]);

        $this->assertSame('203.0.113.20:8080', $this->resolveFor(new PoollessScraper())['url']);
    }

    public function test_an_explicit_proxy_wins_over_the_pool(): void
    {
        config(['larascraper.proxies' => ['203.0.113.20:8080']]);

        $this->assertSame('203.0.113.99:9090', $this->resolveFor(new PinnedProxyScraper())['url']);
    }

    /**
     * Credentials with no address are meaningful on their own — on the http
     * driver they end up as basic auth against the target site. The pool must
     * not swallow them (this is the regression the CI caught).
     */
    public function test_credentials_without_an_address_survive_an_empty_pool(): void
    {
        config(['larascraper.proxies' => []]);

        $parts = $this->resolveFor(new CredentialsOnlyScraper());

        $this->assertNull($parts['url']);
        $this->assertSame('user', $parts['user']);
        $this->assertSame('secret', $parts['pass']);
    }

    public function test_no_proxy_anywhere_resolves_to_nothing(): void
    {
        config(['larascraper.proxies' => []]);

        $this->assertSame(
            ['url' => null, 'user' => null, 'pass' => null],
            $this->resolveFor(new PoollessScraper()),
        );
    }

    public function test_every_configured_proxy_is_reachable(): void
    {
        $pool = ['203.0.113.21:8080', '203.0.113.22:8080', '203.0.113.23:8080'];
        config(['larascraper.proxies' => $pool]);

        $seen = [];
        // Random pick: 200 draws over 3 entries makes a miss vanishingly unlikely
        // without pinning the test to any particular selection order.
        for ($i = 0; $i < 200; $i++) {
            $seen[$this->resolveFor(new PoollessScraper())['url']] = true;
        }

        $this->assertEqualsCanonicalizing($pool, array_keys($seen), 'A configured proxy was never picked.');
    }
}

class PoollessScraper extends Scraper
{
    protected string $driver = 'http';
}

class PinnedProxyScraper extends Scraper
{
    protected string $driver = 'http';

    protected ?string $proxy = '203.0.113.99:9090';
}

class CredentialsOnlyScraper extends Scraper
{
    protected string $driver = 'http';

    protected ?string $proxyUser = 'user';

    protected ?string $proxyPass = 'secret';
}
