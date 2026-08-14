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
    /** @var string|null User agent to claim; null lets the browser answer. */
    protected ?string $userAgent;

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

    /**
     * @var string|null Throttle key: pacing and proxy lockout are scoped to it.
     *                  Null falls back to the host of the URL being fetched.
     */
    protected ?string $throttleKey;

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
        $this->throttleKey = $defaults['throttleKey'] ?? null;
        $this->userAgent = $defaults['userAgent'] ?? null;
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
     * Claim a specific user agent for this fetch.
     *
     * Rarely needed on the browser driver, and worth knowing why: left alone, it
     * asks the Chrome it launches and only drops the word that gives headless
     * away, so what it claims matches what it is — same version, same platform,
     * and Client Hints that agree. A string set here replaces the claim but not
     * the browser, so anything it contradicts is a tell. Set it when you mean to
     * (a site that serves a mobile page, say), not to look more human.
     *
     * @param string|null $userAgent The user agent, or null for the default.
     */
    public function userAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    /**
     * Whether any configured proxy is free to try against this target.
     *
     * Asked right after a refusal, to decide whether retrying is worth anything:
     * with every exit locked out there is nowhere left to go, and hammering the
     * same address is exactly what earned the block.
     */
    protected function hasHealthyProxy(Throttle $throttle): bool
    {
        $proxies = function_exists('config') ? (array) config('larascraper.proxies', []) : [];

        foreach (array_filter($proxies) as $proxy) {
            $parts = static::normalizeProxy($proxy);

            if ($throttle->available($parts['url'] ?? Throttle::DIRECT)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide which proxy this request goes through.
     *
     * An explicit ->proxy() always wins. Otherwise a random entry from
     * config('larascraper.proxies') is used, so a site that blocks one address
     * does not block every scrape. Given a throttle, addresses the target has
     * recently refused are left out of the draw.
     *
     * @param  Throttle|null $throttle Lockout state for this target, if throttled.
     * @return array{url: ?string, user: ?string, pass: ?string}
     */
    protected function resolveProxy(?Throttle $throttle = null): array
    {
        // Anything set explicitly wins, credentials included. They are meaningful
        // on their own: HttpRunner::authenticate() sends them as basic auth to the
        // target site rather than to a proxy, so a scraper may set user and pass
        // with no address at all. Falling through to the pool here would drop them.
        if ($this->proxy || $this->proxyUser || $this->proxyPass) {
            return ['url' => $this->proxy, 'user' => $this->proxyUser, 'pass' => $this->proxyPass];
        }

        $proxies = function_exists('config') ? (array) config('larascraper.proxies', []) : [];
        $proxies = array_map([static::class, 'normalizeProxy'], array_values(array_filter($proxies)));

        // Skip whatever the target has recently refused. Only when every proxy is
        // locked out does the pool fall back to the full list: a stale lockout is a
        // better bet than not trying at all.
        if ($throttle !== null) {
            $healthy = array_values(array_filter(
                $proxies,
                static fn ($p) => $throttle->available($p['url'] ?? Throttle::DIRECT),
            ));

            $proxies = $healthy !== [] ? $healthy : $proxies;
        }

        if ($proxies === []) {
            return ['url' => null, 'user' => null, 'pass' => null];
        }

        return $proxies[array_rand($proxies)];
    }

    /**
     * Accept both spellings of a configured proxy and return its parts.
     *
     * A string may carry credentials inline ('http://user:pass@host:port'); an
     * array spells them out (['url' => ..., 'user' => ..., 'pass' => ...]).
     *
     * Inline credentials are stripped from the URL rather than passed through:
     * Chrome ignores them in --proxy-server, so PuppeteerRunner needs them
     * separately for page.authenticate(). Everything else keeps working because
     * both runners already take user and pass apart from the address.
     *
     * @param  string|array<string, string|null> $proxy
     * @return array{url: ?string, user: ?string, pass: ?string}
     */
    public static function normalizeProxy(string|array $proxy): array
    {
        if (is_array($proxy)) {
            return [
                'url' => $proxy['url'] ?? null,
                'user' => $proxy['user'] ?? null,
                'pass' => $proxy['pass'] ?? null,
            ];
        }

        $parts = parse_url($proxy);

        // Without a scheme, parse_url misreads credentials: 'user:pass@host:port'
        // comes back as scheme "user" plus a path. Prefixing '//' makes it parse
        // the string as an authority, which is what it actually is.
        if ((! is_array($parts) || ! isset($parts['user'])) && str_contains($proxy, '@')) {
            $parts = parse_url('//' . $proxy) ?: $parts;
        }

        // Unparseable, or nothing to strip: hand it over untouched.
        if (! is_array($parts) || ! isset($parts['user'])) {
            return ['url' => $proxy, 'user' => null, 'pass' => null];
        }

        // Rebuild the address without credentials, keeping the scheme only if
        // it was there ('host:port' must not become 'http://host:port').
        $url = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $url .= $parts['host'] ?? '';
        $url .= isset($parts['port']) ? ':' . $parts['port'] : '';

        return [
            'url' => $url,
            'user' => rawurldecode($parts['user']),
            'pass' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
        ];
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

        // Optional capability, not part of the Runner contract: a custom runner
        // that predates it keeps working and is simply never asked.
        if (method_exists($runner, 'userAgent')) {
            $runner->userAgent($this->userAgent);
        }

        $throttle = new Throttle($this->throttleKey ?? $host);

        $attempt = 0;
        $response = [];
        $rotating = false;

        while (++$attempt <= $this->maxRetries) {
            // The exit is chosen per attempt, not once: a proxy the target has just
            // refused is skipped on the next try, which is the whole point of keeping
            // more than one. The runner is reused, so both are re-applied every time
            // — an empty string clears a proxy the previous attempt had set.
            $proxy = $this->resolveProxy($throttle);
            $label = $proxy['url'] ?? Throttle::DIRECT;

            $runner->proxy((string) ($proxy['url'] ?? ''));

            // Cleared explicitly when the chosen exit has none: otherwise a retry
            // through an open proxy would still carry the previous one's credentials.
            $runner->authenticate((string) ($proxy['user'] ?? ''), (string) ($proxy['pass'] ?? ''));

            // Wait our turn against this target, however many processes are asking.
            $throttle->pace();

            $this->log("GETTING: {$this->url} (Attempt #{$attempt})");

            try {
                $response = $runner->run();

                if ($response['success'] ?? false) {
                    // This exit works: forget any lockout it was carrying.
                    $throttle->succeeded($label);
                    break;
                }

                $status = $response['status'] ?? 0;
                $this->log("Error getting {$this->url} on attempt #{$attempt}: {$status}");

                // A refusal is about the address, not the request: lock this exit out
                // of this target for a while (longer each time) and let the next
                // attempt go through another one.
                $refused = in_array($status, [403, 429], true);

                if ($refused) {
                    $seconds = $throttle->lockOut($label);
                    $this->log("Locked out {$label} for {$seconds}s after {$status}");
                }

                // Waiting is for a target that might recover in a moment. A refusal
                // is not that: the address is spent, and the next attempt goes out
                // through a different one, which the pause does nothing to prepare.
                // The target's own rhythm still applies — pace() runs either way.
                $rotating = $refused;

                // Only retriable statuses get another attempt; the rest break out.
                // A 403 joins them only when another exit is free to try — retrying
                // it through the same address would just repeat the refusal.
                $retriable = in_array($status, [408, 429, 500, 502, 503, 504], true)
                    || ($status === 403 && $this->hasHealthyProxy($throttle));

                if (! $retriable) {
                    break;
                }
            } catch (Throwable $e) {
                $this->log("Error getting {$this->url} on attempt #{$attempt}: {$e->getMessage()}");

                $rotating = false;

                $response = [
                    'success' => false,
                    'status' => $response['status'] ?? 0,
                    'error' => $e->getMessage(),
                    'html' => $response['html'] ?? '',
                ];
            }

            if ($attempt < $this->maxRetries && ! $rotating) {
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
            'userAgent' => $this->userAgent,
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
            diagnostics: $response['diagnostics'] ?? [],
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
