<?php

namespace EduLazaro\Larascraper\Support;

use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Spider;
use EduLazaro\Larascraper\Concerns\BuildsActions;
use EduLazaro\Larascraper\Contracts\Runner;
use EduLazaro\Larascraper\Exceptions\RequestException;
use EduLazaro\Larascraper\Exceptions\ScrapeException;
use EduLazaro\Larascraper\Runners\HttpRunner;
use Fiber;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;

/**
 * The per-request chain returned by `$this->scrape($url)`.
 *
 * It holds the per-request configuration (driver, timeout, headers, proxy,
 * body, cookies) seeded from the scraper's properties, plus the ordered browser
 * actions built through the BuildsActions trait (click/type/capture/...). Its
 * terminals perform the fetch and shape the result:
 *
 *   ->crawl(BikeCrawler::class)->run()   // parse via a Crawler -> ScraperResponse
 *   ->crawl('.card h3')->texts()         // inline CSS extraction -> string[]
 *   ->run()                              // raw html -> ScraperResponse
 *   ->capture()->file()                  // a captured binary -> CapturedFile
 *
 * fetch() is the single place a request is performed: it builds the runner once
 * (so a config error such as actions on the http driver fails fast), runs the
 * bounded retry loop, wraps the normalized runner array into a RequestResponse,
 * writes it to `$scraper->request`, memoizes it, and throws RequestException on
 * a request-level failure. It reuses the existing runners and the
 * BuildsActions/ActionBuilder/Condition chain unchanged.
 */
class FetchBuilder
{
    use BuildsActions;

    /** @var Scraper The scraper this fetch belongs to (receives $scraper->request). */
    protected Scraper $scraper;

    /** @var string The URL to fetch. */
    protected string $url;

    /** @var string Which runner to use: 'browser' or 'http'. */
    protected string $driver;

    /** @var array<string, class-string> Map of available drivers to their runner classes. */
    protected array $drivers;

    /** @var int Request timeout in milliseconds. */
    protected int $timeout;

    /** @var array Request headers. */
    protected array $headers;

    /** @var string|null Proxy server, or null. */
    protected ?string $proxy;

    /** @var string|null Proxy username, or null. */
    protected ?string $proxyUser;

    /** @var string|null Proxy password, or null. */
    protected ?string $proxyPass;

    /** @var int Maximum fetch attempts before giving up. */
    protected int $maxRetries;

    /** @var int Seconds to wait between retries. */
    protected int $retryDelay;

    /** @var string HTTP method for the 'http' driver. */
    protected string $httpMethod;

    /** @var mixed The request body for non-GET requests, or null. */
    protected mixed $body;

    /** @var string Body format: 'form' or 'json'. */
    protected string $bodyFormat;

    /** @var array Request cookies as a name => value map. */
    protected array $cookies;

    /** @var string|null Cookie domain, or null. */
    protected ?string $cookieDomain;

    /** @var Session|null A shared cookie jar threaded across a crawl, or null. */
    protected ?Session $session;

    /** @var RequestResponse|null The memoized fetch result (idempotency). */
    protected ?RequestResponse $fetched = null;

    /**
     * @param Scraper $scraper The owning scraper.
     * @param string $url The URL to fetch.
     * @param array $defaults Per-request configuration from Scraper::fetchDefaults().
     */
    public function __construct(Scraper $scraper, string $url, array $defaults = [])
    {
        $this->scraper = $scraper;
        $this->url = $url;

        $this->driver = $defaults['driver'] ?? 'browser';
        $this->drivers = $defaults['drivers'] ?? [];
        $this->timeout = $defaults['timeout'] ?? 20000;
        $this->headers = $defaults['headers'] ?? [];
        $this->proxy = $defaults['proxy'] ?? null;
        $this->proxyUser = $defaults['proxyUser'] ?? null;
        $this->proxyPass = $defaults['proxyPass'] ?? null;
        $this->maxRetries = $defaults['maxRetries'] ?? 3;
        $this->retryDelay = $defaults['retryDelay'] ?? 15;
        $this->httpMethod = $defaults['method'] ?? 'GET';
        $this->body = $defaults['body'] ?? null;
        $this->bodyFormat = $defaults['bodyFormat'] ?? 'form';
        $this->cookies = $defaults['cookies'] ?? [];
        $this->cookieDomain = $defaults['cookieDomain'] ?? null;
        $this->session = $defaults['session'] ?? null;
    }

    /**
     * Choose the runner driver.
     *
     * @param string $driver 'browser' (Puppeteer) or 'http' (plain HTTP).
     * @throws InvalidArgumentException If the driver is unknown.
     */
    public function driver(string $driver): static
    {
        if (!isset($this->drivers[$driver])) {
            $available = implode(', ', array_keys($this->drivers));
            throw new InvalidArgumentException(
                "Unknown scraper driver [{$driver}]. Available drivers: {$available}."
            );
        }

        $this->driver = $driver;
        return $this;
    }

    /**
     * Set timeout in milliseconds.
     */
    public function timeout(int $ms): static
    {
        $this->timeout = $ms;
        return $this;
    }

    /**
     * Set request headers.
     */
    public function headers(array $headers): static
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * Set the HTTP method (only meaningful for the 'http' driver).
     */
    public function method(string $method): static
    {
        $this->httpMethod = strtoupper($method);
        return $this;
    }

    /**
     * Shortcut: POST with a body (form by default).
     */
    public function post(mixed $body = [], string $format = 'form'): static
    {
        $this->httpMethod = 'POST';
        $this->body = $body;
        $this->bodyFormat = $format === 'json' ? 'json' : 'form';
        return $this;
    }

    /**
     * Set the request body.
     */
    public function body(mixed $body): static
    {
        $this->body = $body;
        return $this;
    }

    /** Send the body as JSON. */
    public function asJson(): static
    {
        $this->bodyFormat = 'json';
        return $this;
    }

    /** Send the body as x-www-form-urlencoded. */
    public function asForm(): static
    {
        $this->bodyFormat = 'form';
        return $this;
    }

    /**
     * Set request cookies (name => value), with optional domain.
     */
    public function cookies(array $cookies, ?string $domain = null): static
    {
        $this->cookies = $cookies;
        $this->cookieDomain = $domain;
        return $this;
    }

    /**
     * Thread a shared cookie jar through this request.
     *
     * On a driver that carries outbound cookies, the jar is merged into the
     * request cookies before the fetch (under any explicit per-call cookies) and
     * receives Set-Cookie after a SUCCESSFUL fetch, so a crawl keeps a single
     * accumulating session. On a driver that cannot carry cookies (the browser
     * driver, for now) the jar is a documented no-op.
     */
    public function session(Session $session): static
    {
        $this->session = $session;
        return $this;
    }

    /**
     * Set proxy with optional auth.
     */
    public function proxy(string $proxy, ?string $user = null, ?string $pass = null): static
    {
        $this->proxy = $proxy;
        $this->proxyUser = $user;
        $this->proxyPass = $pass;
        return $this;
    }

    /**
     * Set retry attempts and delay.
     *
     * @param int $attempts The number of attempts before giving up.
     * @param int $seconds The seconds to wait between attempts.
     */
    public function retry(int $attempts, int $seconds): static
    {
        $this->maxRetries = $attempts;
        $this->retryDelay = $seconds;
        return $this;
    }

    /**
     * Terminal: fetch, then either parse via a Crawler class or extract inline.
     *
     * When $target is a Crawler subclass, `->run()` parses through it. When it is
     * a CSS selector string, `->text()` / `->texts()` extract inline.
     *
     * @param string $target A Crawler class-string, or a CSS selector.
     * @return Crawl
     */
    public function crawl(string $target): Crawl
    {
        return new Crawl(
            $this,
            $target,
            class_exists($target) && is_subclass_of($target, \EduLazaro\Larascraper\Crawler::class)
        );
    }

    /**
     * Perform the fetch and return the HTTP-layer response.
     *
     * Idempotent: memoized on first call so Crawl and the other terminals can
     * share one request. Dispatches to one of two paths that end identically
     * (both funnel through wrapResponse(), so $this->request, the memo, the
     * RequestException-on-failure and the Set-Cookie store-back behave the same):
     *
     *   - The BLOCKING path (fetchBlocking()) runs the driver's runner inline
     *     with the bounded retry loop. This is the default and is unchanged.
     *   - The CONCURRENT path (fetchAsync()) engages only inside a Spider pool
     *     wave (a scheduler is active) while running in a Fiber on an http-style
     *     driver: it hands a fully-resolved request spec to the scheduler via
     *     Fiber::suspend() and resumes with the settled response, so many
     *     scrapers overlap their network I/O in one Http::pool() wave.
     *
     * @return RequestResponse
     * @throws RequestException When the request fails after the bounded retries.
     */
    public function fetch(): RequestResponse
    {
        if ($this->fetched !== null) {
            return $this->fetched;
        }

        if ($this->shouldRunAsync()) {
            return $this->fetchAsync();
        }

        return $this->fetchBlocking();
    }

    /**
     * Whether this fetch should suspend into a Spider pool scheduler instead of
     * blocking: a scheduler is active, we are running inside a Fiber, and the
     * driver's runner is HTTP-poolable (an HttpRunner or a subclass). Any other
     * driver, or a fetch outside a pool, takes the blocking path unchanged.
     *
     * @return bool
     */
    protected function shouldRunAsync(): bool
    {
        $runnerClass = $this->drivers[$this->driver] ?? null;

        return $runnerClass !== null
            && is_a($runnerClass, HttpRunner::class, true)
            && Spider::schedulerActive()
            && Fiber::getCurrent() !== null;
    }

    /**
     * The blocking fetch: build the runner once (so a config error such as
     * actions on the http driver fails fast), run the bounded retry loop, then
     * wrap the normalized runner array. Behaviour is identical to the original
     * fetch(): this is the path every non-pooled Scraper::run() takes.
     *
     * @return RequestResponse
     * @throws RequestException When the request fails after the bounded retries.
     */
    protected function fetchBlocking(): RequestResponse
    {
        $host = parse_url($this->url, PHP_URL_HOST) ?: '';

        /** @var class-string<Runner> $runnerClass */
        $runnerClass = $this->drivers[$this->driver];

        $runner = $runnerClass::on($this->url)
            ->timeout($this->timeout)
            ->withHeaders($this->headers)
            ->withActions($this->actions)
            ->method($this->httpMethod)
            ->body($this->body, $this->bodyFormat);

        // A shared Session only rides along on drivers that can carry outbound
        // cookies; on one that cannot (the browser driver, for now) the jar is a
        // documented no-op instead of a throw, so a login/CSRF crawl simply has
        // no effect there rather than breaking. Merge the jar's cookies for this
        // host UNDER any explicit per-call cookies (which win), so an established
        // session rides along without clobbering an override.
        $sessionActive = $this->session !== null && $runner->supportsCookies();

        if ($sessionActive) {
            $this->cookies = array_merge($this->session->cookiesFor($host), $this->cookies);
        }

        $runner->cookies($this->cookies, $this->cookieDomain);

        if ($this->proxy) {
            $runner->proxy($this->proxy);
        }

        if ($this->proxyUser && $this->proxyPass) {
            $runner->authenticate($this->proxyUser, $this->proxyPass);
        }

        $attempt = 0;
        $response = [];

        while (++$attempt <= $this->maxRetries) {
            $this->log("GETTING: {$this->url} (Attempt #{$attempt})");

            try {
                $response = $runner->run();

                if ($response['success'] ?? false) {
                    break;
                }

                $status = $response['status'] ?? 0;
                $this->log("Error getting {$this->url} on attempt #{$attempt}: {$status}");

                // Only retriable statuses get another attempt; the rest break out.
                if (!in_array($status, [408, 429, 500, 502, 503, 504], true)) {
                    break;
                }
            } catch (Throwable $e) {
                $this->log("Error getting {$this->url} on attempt #{$attempt}: {$e->getMessage()}");

                $response = [
                    'success' => false,
                    'status' => $response['status'] ?? 0,
                    'error' => $e->getMessage(),
                    'html' => $response['html'] ?? '',
                ];
            }

            if ($attempt < $this->maxRetries) {
                $this->log("Retrying in {$this->retryDelay} seconds...");
                sleep($this->retryDelay);
            }
        }

        return $this->wrapResponse($response, $host, $sessionActive);
    }

    /**
     * The concurrent fetch: resolve the FULL request config (url, method,
     * headers, timeout, session-merged cookies, proxy, basic auth, body, retry
     * config), hand it to the active Spider pool scheduler with Fiber::suspend(),
     * and resume with the settled transport result. The result is normalized by
     * the SAME runner logic the blocking path uses (HttpRunner::normalizeResponse
     * / transportFailure) so the two paths cannot drift, then wrapped identically.
     *
     * The spec carries session cookies + proxy + basic auth + headers + body (not
     * just headers/timeout), so a pooled request cannot silently diverge from
     * HttpRunner::run().
     *
     * @return RequestResponse
     * @throws RequestException When the settled response is a request-level failure.
     */
    protected function fetchAsync(): RequestResponse
    {
        $host = parse_url($this->url, PHP_URL_HOST) ?: '';

        // The async path only engages on an http-style driver, which carries
        // outbound cookies, so a shared jar always rides along here. Merge the
        // jar's cookies UNDER any explicit per-call cookies (which win), exactly
        // like the blocking path.
        $sessionActive = $this->session !== null;

        $cookies = $this->cookies;

        if ($sessionActive) {
            $cookies = array_merge($this->session->cookiesFor($host), $cookies);
        }

        $spec = [
            'url' => $this->url,
            'method' => strtoupper($this->httpMethod),
            'headers' => $this->headers,
            'timeout' => $this->timeout,
            'cookies' => $cookies,
            'cookieDomain' => $this->cookieDomain ?: $host,
            'proxy' => $this->proxy,
            'proxyUser' => $this->proxyUser,
            'proxyPass' => $this->proxyPass,
            'body' => $this->body,
            'bodyFormat' => $this->bodyFormat,
            'maxRetries' => $this->maxRetries,
            'retryDelay' => $this->retryDelay,
        ];

        // Park this fiber; the scheduler resumes it with the settled Http::pool
        // result: a Response, or a Throwable on a connection-level failure.
        $result = Fiber::suspend($spec);

        /** @var class-string<HttpRunner> $runnerClass */
        $runnerClass = $this->drivers[$this->driver];

        if ($result instanceof Response) {
            $response = $runnerClass::normalizeResponse($result);
        } else {
            $message = $result instanceof Throwable ? $result->getMessage() : 'request_failed';
            $response = $runnerClass::transportFailure($message);
        }

        return $this->wrapResponse($response, $host, $sessionActive);
    }

    /**
     * Turn a normalized runner array into a RequestResponse and finish the fetch.
     *
     * Shared by both fetch paths so the memoization, the $scraper->request write,
     * the RequestException-on-failure and the Set-Cookie store-back are done in
     * exactly one place.
     *
     * @param array $response The normalized runner result array.
     * @param string $host The request URL's host (for the session store-back).
     * @param bool $sessionActive Whether a cookie-carrying shared jar is threaded.
     * @return RequestResponse
     * @throws RequestException When the response is a request-level failure.
     */
    protected function wrapResponse(array $response, string $host, bool $sessionActive): RequestResponse
    {
        // A captured file/binary arrives base64-encoded from the runner.
        $file = isset($response['file'])
            ? new CapturedFile(base64_decode($response['file']), $response['contentType'] ?? null)
            : null;

        $req = new RequestResponse(
            status: $response['status'] ?? 0,
            error: $response['error'] ?? null,
            html: $response['html'] ?? '',
            file: $file,
            contentType: $response['contentType'] ?? null,
            cookies: $response['cookies'] ?? [],
        );

        // Make it reachable as $this->request inside the scraper, and memoize.
        $this->scraper->request = $req;
        $this->fetched = $req;

        if (!($response['success'] ?? false)) {
            throw new RequestException($req);
        }

        // Accumulate Set-Cookie back into the shared jar so the next request in
        // the crawl carries them, but ONLY on a successful fetch: cookies from a
        // failed response (an error page, an expired-session redirect) must never
        // clobber the good session cookies later targets rely on. The jar is
        // shared state; cookies still never surface on ScraperResponse.
        if ($sessionActive) {
            $this->session->store($host, $req->cookies);
        }

        return $req;
    }

    /**
     * Terminal: fetch and wrap the raw HTML into a ScraperResponse.
     *
     * @return ScraperResponse
     */
    public function run(): ScraperResponse
    {
        $req = $this->fetch();

        return new ScraperResponse(data: $req->html, success: true);
    }

    /**
     * Terminal: fetch and return the captured file.
     *
     * @return CapturedFile
     * @throws ScrapeException When no file was captured.
     */
    public function file(): CapturedFile
    {
        $req = $this->fetch();

        if ($req->file === null) {
            throw new ScrapeException('no_file');
        }

        return $req->file;
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
