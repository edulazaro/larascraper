<?php

namespace EduLazaro\Larascraper;

use EduLazaro\Larascraper\Runners\PuppeteerRunner;
use EduLazaro\Larascraper\Runners\HttpRunner;
use EduLazaro\Larascraper\Support\FetchBuilder;
use EduLazaro\Larascraper\Support\PendingScraper;
use EduLazaro\Larascraper\Support\RequestResponse;
use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Support\Session;
use EduLazaro\Larascraper\Exceptions\ScrapeException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use LogicException;

/**
 * Base class for scrapers.
 *
 * A scraper is the orchestration layer. It no longer holds the HTML, the
 * crawler or the fetched file: those belong to a per-request FetchBuilder,
 * created with `$this->scrape($url)`, that carries the browser actions and
 * performs the fetch. What lives here is the strategy — how many retries, which
 * driver, proxy, headers — held as properties and seeded into every FetchBuilder
 * through fetchDefaults().
 *
 * Two external entry points reach the same instance machinery:
 *
 *   MyScraper::run($url);              // static entry, one call
 *   MyScraper::with(driver: 'http')   // configure, then
 *       ->run($url);                   //   run the configured instance
 *
 * Because a single method name cannot be both static-callable as `Foo::run()`
 * and preserve instance state through `$configured->run()`, run()/with()/make()
 * are STATIC here and with() returns a small PendingScraper wrapper that carries
 * the configured instance to its own run().
 *
 * The default handle() returns a ScraperResponse (via a FetchBuilder terminal),
 * but a scraper may declare custom methods and a handle() returning any scalar.
 */
abstract class Scraper
{
    /**
     * The HTTP-layer response of the last fetch, written by FetchBuilder::fetch().
     *
     * Null until the first fetch happens; then it exposes the wire facts
     * (status, html, cookies, an optional captured file) inside handle().
     */
    public ?RequestResponse $request = null;

    /** @var string The URL being scraped (set by callers/handle args, not by the base). */
    protected string $url;

    /** @var string Which runner to use: 'browser' (Puppeteer, default) or 'http'. */
    protected string $driver = 'browser';

    /** @var array<string, class-string> Map of available drivers to their runner classes. */
    protected array $drivers = [
        'browser' => PuppeteerRunner::class,
        'http' => HttpRunner::class,
    ];

    /** @var int Request timeout in milliseconds. */
    protected int $timeout = 20000;

    /** @var array Request headers. */
    protected array $headers = [];

    /** @var string|null Proxy server (IP:PORT or full URL), or null. */
    protected ?string $proxy = null;

    /** @var string|null Proxy username, or null. */
    protected ?string $proxyUser = null;

    /** @var string|null Proxy password, or null. */
    protected ?string $proxyPass = null;

    /** @var int Maximum fetch attempts before giving up. */
    protected int $tries = 3;

    /** @var int Seconds to wait between retries. */
    protected int $retryDelay = 15;

    /** @var string HTTP method for the 'http' driver (browser driver is GET-only). */
    protected string $httpMethod = 'GET';

    /** @var mixed The request body for non-GET requests, or null. */
    protected mixed $body = null;

    /** @var string Body format: 'form' (x-www-form-urlencoded) or 'json'. */
    protected string $bodyFormat = 'form';

    /** @var array Request cookies as a name => value map. */
    protected array $cookies = [];

    /** @var string|null Cookie domain (defaults to the URL host), or null. */
    protected ?string $cookieDomain = null;

    /**
     * @var Session|null A shared cookie jar threaded across a crawl, or null.
     *
     * The scraper is session-AWARE, not the owner: the jar is created elsewhere
     * (a Spider, or the caller) and injected with useSession(), then handed to
     * every FetchBuilder via fetchDefaults() so cookies accumulate across fetches.
     */
    protected ?Session $session = null;

    /**
     * Create a new scraper instance through Laravel's service container, so
     * constructor dependencies are resolved.
     *
     * @param mixed ...$params Constructor arguments.
     * @return static
     */
    public static function make(mixed ...$params): static
    {
        return app(static::class, $params);
    }

    /**
     * Run the scraper in one call: make an instance and invoke handle().
     *
     * @param mixed ...$params Positional, named, or array arguments for handle().
     * @return ScraperResponse
     */
    public static function run(mixed ...$params): ScraperResponse
    {
        return static::make()->handleToResponse($params);
    }

    /**
     * Invoke handle() and normalize its return into a ScraperResponse.
     *
     * Wrapping rules:
     *   - a raw value returned by handle() becomes ScraperResponse(data: $value)
     *     with success = true;
     *   - a ScraperResponse returned by handle() (e.g. via a crawl() terminal, or
     *     $this->fail() / $this->ok()) passes through unchanged;
     *   - a ScrapeException thrown from handle() (or bubbling from a nested
     *     method) is caught and folded into
     *     ScraperResponse(success: false, error: <message>).
     *
     * Request-level failures still throw RequestException to the caller; the HTTP
     * facts live on $this->request, never on the returned ScraperResponse.
     *
     * @param array $params Positional, named, or array arguments for handle().
     * @return ScraperResponse
     */
    public function handleToResponse(array $params = []): ScraperResponse
    {
        try {
            $result = $this->callHandle($params);
        } catch (ScrapeException $e) {
            return new ScraperResponse(success: false, error: $e->getMessage());
        }

        return $result instanceof ScraperResponse
            ? $result
            : new ScraperResponse(data: $result);
    }

    /**
     * Build a FAILED ScraperResponse from inside handle():
     * `return $this->fail('no_text')`. Same effect as throwing a ScrapeException,
     * but by return, so the handle keeps a single exit style.
     *
     * @param string $error The scrape-level error code.
     * @param mixed $data Optional data to keep alongside the failure.
     * @return ScraperResponse
     */
    protected function fail(string $error, mixed $data = null): ScraperResponse
    {
        return new ScraperResponse(data: $data, success: false, error: $error);
    }

    /**
     * Build a SUCCESSFUL ScraperResponse explicitly: `return $this->ok($data)`.
     * Equivalent to returning $data raw; provided for symmetry with fail().
     *
     * @param mixed $data The scrape data.
     * @return ScraperResponse
     */
    protected function ok(mixed $data = null): ScraperResponse
    {
        return new ScraperResponse(data: $data, success: true);
    }

    /**
     * Configure a fresh instance's properties, then hand it to a PendingScraper
     * so the configuration survives to `->run()`.
     *
     * @param mixed ...$params Property values to inject (named or an assoc array).
     * @return PendingScraper
     */
    public static function with(mixed ...$params): PendingScraper
    {
        $instance = static::make();
        $instance->applyWith($params);

        return new PendingScraper($instance);
    }

    /**
     * Make the scraper session-aware by injecting a shared cookie jar.
     *
     * The Session is NOT owned here: one mutable jar is threaded into every
     * FetchBuilder this scraper creates, so cookies established on one fetch are
     * carried into the next. Chainable.
     *
     * @param Session $session The shared cookie jar.
     * @return static
     */
    public function useSession(Session $session): static
    {
        $this->session = $session;
        return $this;
    }

    /**
     * Configure a fresh instance with a shared Session, then hand it to a
     * PendingScraper so it survives to `->run()`. Mirrors with().
     *
     * @param Session $session The shared cookie jar.
     * @return PendingScraper
     */
    public static function withSession(Session $session): PendingScraper
    {
        $instance = static::make();
        $instance->useSession($session);

        return new PendingScraper($instance);
    }

    /**
     * Start a per-request chain for the given URL.
     *
     * The returned FetchBuilder carries the browser actions (via BuildsActions)
     * and the per-request config seeded from this scraper's properties, and
     * exposes the terminals crawl()/run()/file().
     *
     * @param string $url The URL to scrape.
     * @return FetchBuilder
     */
    public function scrape(string $url): FetchBuilder
    {
        return new FetchBuilder($this, $url, $this->fetchDefaults());
    }

    /**
     * Invoke handle() with arguments resolved by reflection.
     *
     * Arguments may be positional, named, or a single associative array mapped
     * to handle()'s parameters by name. A single array passed to a
     * single-parameter handle() is forwarded whole as that argument (the
     * "attribute bag") only when the parameter accepts an array.
     *
     * @param array $params Positional, named, or array arguments for handle().
     * @return mixed The value returned by handle().
     * @throws LogicException When the scraper does not implement handle().
     */
    public function callHandle(array $params = []): mixed
    {
        if (!method_exists($this, 'handle')) {
            throw new LogicException("The scraper class " . static::class . " must implement a `handle` method.");
        }

        $reflection = new ReflectionMethod($this, 'handle');
        $refParams  = $reflection->getParameters();

        // The single-parameter "bag": forward the array whole as the sole argument, but
        // ONLY when that parameter actually accepts an array (typed `array`/`iterable`/
        // `mixed`, or untyped). For a concrete typed param — e.g. handle(File $file) — a
        // single array is NOT a bag: it falls through to the name/position mapping below,
        // so callHandle(['file' => $file]) binds $file = $file instead of the whole array.
        $singleArrayBag = false;
        if (count($params) === 1
            && array_key_exists(0, $params)
            && is_array($params[0])
            && count($refParams) === 1
        ) {
            // The param "accepts the array bag" if it is untyped, or its type is — or, for a
            // union, includes — array/iterable/mixed. A concrete object/scalar type is NOT a
            // bag, so its array is mapped by name/position below.
            $bagType = $refParams[0]->getType();
            $candidateTypes = $bagType instanceof ReflectionUnionType ? $bagType->getTypes() : [$bagType];

            foreach ($candidateTypes as $candidate) {
                if ($candidate === null
                    || ($candidate instanceof ReflectionNamedType
                        && in_array($candidate->getName(), ['array', 'iterable', 'mixed'], true))
                ) {
                    $singleArrayBag = true;
                    break;
                }
            }
        }

        if ($singleArrayBag) {
            $named      = [];
            $positional = [$params[0]];
        } else {
            // Allow: callHandle(['k'=>v]) or callHandle(['a','b']) or callHandle(['name'=>'a'])
            if (count($params) === 1 && array_key_exists(0, $params) && is_array($params[0])) {
                $params = $params[0];
            }

            // Detect if $params is associative (PHP named args end up as assoc)
            $isAssoc    = is_array($params) && !array_is_list($params);
            $named      = $isAssoc ? $params : [];
            $positional = $isAssoc ? [] : (is_array($params) ? array_values($params) : []);
        }

        $finalArgs = [];
        $posIndex  = 0;

        foreach ($refParams as $rp) {
            $name = $rp->getName();

            if (array_key_exists($name, $named)) {
                $value = $named[$name];
            } elseif ($posIndex < count($positional)) {
                $value = $positional[$posIndex++];
            } elseif ($rp->isDefaultValueAvailable()) {
                // Respect handle() defaults
                $value = $rp->getDefaultValue();
            } else {
                // BC: missing becomes null (typehints decide later)
                $value = null;
            }

            $finalArgs[] = $value;
        }

        return $this->handle(...$finalArgs);
    }

    /**
     * Inject parameters into this scraper's properties by name.
     *
     * @param array $params The parameters to inject (named or a single assoc array).
     * @return void
     */
    public function applyWith(array $params = []): void
    {
        if (!empty($params[0]) && is_array($params[0])) {
            $params = $params[0];
        }

        $reflector = new ReflectionClass($this);
        $properties = $reflector->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);

        foreach ($properties as $property) {
            $name = $property->getName();

            if (array_key_exists($name, $params) || isset($this->{$name})) {
                $property->setAccessible(true);
                $property->setValue($this, $params[$name] ?? $this->{$name});
            }
        }
    }

    /**
     * Build the per-request configuration seeded into every FetchBuilder.
     *
     * @return array{
     *     driver: string,
     *     drivers: array<string, class-string>,
     *     timeout: int,
     *     headers: array,
     *     proxy: ?string,
     *     proxyUser: ?string,
     *     proxyPass: ?string,
     *     maxRetries: int,
     *     retryDelay: int,
     *     method: string,
     *     body: mixed,
     *     bodyFormat: string,
     *     cookies: array,
     *     cookieDomain: ?string,
     *     session: ?Session
     * }
     */
    protected function fetchDefaults(): array
    {
        return [
            'driver' => $this->driver,
            'drivers' => $this->drivers,
            'timeout' => $this->timeout,
            'headers' => $this->headers,
            'proxy' => $this->proxy,
            'proxyUser' => $this->proxyUser,
            'proxyPass' => $this->proxyPass,
            'maxRetries' => $this->tries,
            'retryDelay' => $this->retryDelay,
            'method' => $this->httpMethod,
            'body' => $this->body,
            'bodyFormat' => $this->bodyFormat,
            'cookies' => $this->cookies,
            'cookieDomain' => $this->cookieDomain,
            'session' => $this->session,
        ];
    }

    /**
     * Write a progress line to stdout, suppressed while running tests.
     *
     * @param string $message
     * @return void
     */
    protected function log(string $message): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        echo $message . "\n";
    }
}
