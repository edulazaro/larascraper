<?php

namespace EduLazaro\Larascraper\Runners;

use EduLazaro\Larascraper\Contracts\Runner;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;

/**
 * Run a scraper using a plain HTTP request (no browser).
 *
 * This is a lightweight alternative to {@see PuppeteerRunner}: it fetches the
 * URL with Laravel's HTTP client instead of launching a headless Chromium.
 * It is much faster and cheaper, but cannot execute browser actions or render
 * JavaScript, so it only suits static pages / APIs.
 */
class HttpRunner implements Runner
{
    protected string $url;
    protected ?string $proxy = null;
    protected ?string $user = null;
    protected ?string $password = null;
    protected array $headers = [];

    /** @var string|null User agent, sent as a header; null falls back (see resolveUserAgent()). */
    protected ?string $userAgent = null;
    protected int $timeout = 20000;
    protected string $method = 'GET';
    protected mixed $body = null;
    protected string $bodyFormat = 'form';
    protected array $cookies = [];
    protected ?string $cookieDomain = null;

    /**
     * Initialize the runner with a target URL.
     *
     * @param string $url The URL to scrape.
     * @return static
     */
    public static function on(string $url): static
    {
        $runnerInstance = new static();
        $runnerInstance->url = $url;
        return $runnerInstance;
    }

    /**
     * Set HTTP basic authentication credentials.
     *
     * @param string $user Username.
     * @param string $password Password.
     * @return static
     */
    public function authenticate(string $user, string $password): static
    {
        $this->user = $user;
        $this->password = $password;
        return $this;
    }

    /**
     * Set a proxy server (IP:PORT or full URL).
     *
     * @param string $proxy Proxy address.
     * @return static
     */
    public function proxy(string $proxy): static
    {
        $this->proxy = $proxy;
        return $this;
    }

    /**
     * Set the user agent sent with the request.
     *
     * Unlike the browser driver there is nobody to ask here — no Chrome is
     * launched — so null does not mean "send none": it means "no opinion", and
     * the request falls back to config and then to DEFAULT_USER_AGENT. An
     * explicit User-Agent in withHeaders() still wins over all of it.
     *
     * @param string|null $userAgent The user agent, or null to fall back.
     * @return static
     */
    public function userAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    /**
     * Last-resort user agent, used when config has nothing usable to say.
     *
     * Written out because there is nobody to ask: no browser launches on this
     * driver. It ages, which is why config is the place to change it — this is
     * only the floor, so that no request ever goes out announcing itself as a
     * script. Kept public because Spider's concurrent path builds its own
     * requests and has to land on the same answer.
     */
    public const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        . ' (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

    /**
     * Settle on a user agent: what the caller chose, else config, else a browser.
     *
     * There is nobody to ask on this driver — no Chrome launches — so the default
     * is written out. It is a browser rather than nothing because "nothing" is
     * not silence: the HTTP client fills in 'GuzzleHttp/7', and a scraping
     * library whose out-of-the-box request announces itself as a script has a
     * defect, not a neutral default.
     *
     * ⚠️ AN EMPTY CONFIG VALUE MEANS "CLAIM NOTHING", and returns null so no
     * header is added at all. That is the deliberate way back to what the package
     * sent before this existed, for anyone who was relying on it. Only an EMPTY
     * value does that — a missing key still gets the default, since the package
     * config is merged underneath the app's.
     *
     * @param string|null $explicit What the scraper asked for, if anything.
     * @return string|null The user agent, or null to send none.
     */
    public static function resolveUserAgent(?string $explicit = null): ?string
    {
        if (! empty($explicit)) {
            return $explicit;
        }

        if (! function_exists('config')) {
            return self::DEFAULT_USER_AGENT;
        }

        $configured = config('larascraper.http_user_agent', self::DEFAULT_USER_AGENT);

        // '' is an opt-out; anything unusable falls back rather than sending junk.
        if ($configured === '' || $configured === null) {
            return null;
        }

        return is_string($configured) ? $configured : self::DEFAULT_USER_AGENT;
    }

    /**
     * Set request headers.
     *
     * @param array $headers Associative array of headers.
     * @return static
     */
    public function withHeaders(array $headers): static
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * Set browser actions. Genuine browser interactions are not supported here.
     *
     * The declarative `capture` action is the one exception: it does not drive
     * the page, it only signals "expect a binary body". The http runner already
     * detects that on its own (see looksBinary()), so a lone capture() is a
     * harmless no-op and is filtered out. Any real browser action (click, type,
     * wait, ...) still throws, because a plain HTTP request cannot perform it.
     *
     * @param array $actions List of action descriptors.
     * @throws LogicException If a genuine browser action is provided.
     * @return static
     */
    public function withActions(array $actions): static
    {
        $browserActions = array_filter(
            $actions,
            fn ($action) => ($action['type'] ?? null) !== 'capture'
        );

        if (!empty($browserActions)) {
            throw new LogicException(
                'The "http" driver does not support browser actions (click, type, wait, etc.). '
                . 'Use the "browser" driver for pages that require interaction or JavaScript rendering.'
            );
        }

        return $this;
    }

    /**
     * Set the timeout in milliseconds.
     *
     * @param int $ms Timeout duration in milliseconds.
     * @return static
     */
    public function timeout(int $ms): static
    {
        $this->timeout = $ms;
        return $this;
    }

    /**
     * Set the HTTP method (GET, POST, PUT, PATCH, DELETE).
     *
     * @param string $method HTTP verb.
     * @return static
     */
    public function method(string $method): static
    {
        $this->method = strtoupper($method);
        return $this;
    }

    /**
     * Set the request body for non-GET requests.
     *
     * @param mixed  $body   Payload (array for form/json), or null.
     * @param string $format 'form' or 'json'.
     * @return static
     */
    public function body(mixed $body, string $format = 'form'): static
    {
        $this->body = $body;
        $this->bodyFormat = $format === 'json' ? 'json' : 'form';
        return $this;
    }

    /**
     * Set request cookies.
     *
     * @param array       $cookies Associative array of cookie name => value.
     * @param string|null $domain  Cookie domain (defaults to the URL host).
     * @return static
     */
    public function cookies(array $cookies, ?string $domain = null): static
    {
        $this->cookies = $cookies;
        $this->cookieDomain = $domain;
        return $this;
    }

    /**
     * The http driver carries outbound cookies, so a shared Session threads
     * through it.
     *
     * @return bool
     */
    public function supportsCookies(): bool
    {
        return true;
    }

    /**
     * Run the HTTP request and return the normalized result array.
     *
     * @return array{success: bool, status: int, html: ?string, error: ?string, file: ?string, contentType: ?string}
     */
    public function run(): array
    {
        try {
            // An explicit header still outranks it: writing one by hand is a
            // deliberate act, and this is only a default. With nothing claimed,
            // no header is added at all and the client sends what it always did.
            $userAgent = static::resolveUserAgent($this->userAgent);

            $headers = $userAgent === null
                ? $this->headers
                : array_merge(['User-Agent' => $userAgent], $this->headers);

            $request = Http::withHeaders($headers)
                ->timeout((int) max(1, ceil($this->timeout / 1000)));

            if ($this->proxy) {
                $request = $request->withOptions(['proxy' => $this->proxy]);
            }

            // An empty user clears the credentials rather than sending blank ones,
            // so a reused runner can drop the auth of a previous attempt.
            if (! empty($this->user) && $this->password !== null) {
                $request = $request->withBasicAuth($this->user, $this->password);
            }

            if (!empty($this->cookies)) {
                $domain = $this->cookieDomain ?: (parse_url($this->url, PHP_URL_HOST) ?: '');
                $request = $request->withCookies($this->cookies, $domain);
            }

            if ($this->method === 'GET') {
                $response = $request->get($this->url);
            } else {
                $request = $this->bodyFormat === 'json' ? $request->asJson() : $request->asForm();
                $payload = is_array($this->body) ? $this->body : (array) ($this->body ?? []);
                $verb = strtolower($this->method);
                $response = $request->{$verb}($this->url, $payload);
            }

            return static::normalizeResponse($response);
        } catch (Throwable $e) {
            return static::transportFailure($e->getMessage());
        }
    }

    /**
     * Normalize a settled Laravel HTTP Response into the runner result array.
     *
     * This is the single source of truth for turning a Response into the shape
     * Scraper::run() consumes, so the sequential run() and the Spider's
     * concurrent pool (which settles its requests through Http::pool) cannot
     * drift apart on how a body, a status, a captured file or Set-Cookie is read.
     *
     * @param Response $response The settled HTTP response.
     * @return array{success: bool, status: int, html: ?string, error: ?string, file: ?string, contentType: ?string, cookies: array<string, string>}
     */
    public static function normalizeResponse(Response $response): array
    {
        $body = $response->body();
        $contentType = $response->header('Content-Type') ?: null;

        // A binary/file response (a PDF, a ZIP, an image...) is exposed as a
        // captured file so `$result->file` works with the http driver, exactly
        // like capture() does on the browser driver. Text responses (HTML,
        // JSON, XML) stay in `html`.
        $isBinary = static::looksBinary($contentType, $body);

        return [
            'success' => $response->successful(),
            'status' => $response->status(),
            'html' => $isBinary ? '' : $body,
            'error' => $response->successful() ? null : "HTTP {$response->status()}",
            'file' => $isBinary ? base64_encode($body) : null,
            'contentType' => $contentType,
            'cookies' => static::parseSetCookies($response->headers()['Set-Cookie'] ?? []),
        ];
    }

    /**
     * The runner result array for a transport-level failure (no response body):
     * a refused connection, a DNS error, a timeout. Status is 0.
     *
     * @param string $message The transport error message.
     * @return array{success: bool, status: int, html: ?string, error: ?string, file: ?string, contentType: ?string, cookies: array<string, string>}
     */
    public static function transportFailure(string $message): array
    {
        return [
            'success' => false,
            'status' => 0,
            'html' => null,
            'error' => $message,
            'file' => null,
            'contentType' => null,
            'cookies' => [],
        ];
    }

    /**
     * Decide whether a response body should be treated as a downloadable file
     * (returned via `file`) rather than text (returned via `html`).
     *
     * Textual types (text/*, JSON, XML, SVG) stay in `html`. Known file families
     * (PDF, archives, office docs, images, audio, video, fonts) become a file.
     * When the type is missing, a few magic numbers are sniffed.
     *
     * @param string|null $contentType The response Content-Type header.
     * @param string $body The response body.
     * @return bool
     */
    protected static function looksBinary(?string $contentType, string $body): bool
    {
        $type = strtolower(trim(explode(';', (string) $contentType)[0]));

        if ($type !== '') {
            if (str_starts_with($type, 'text/')) {
                return false;
            }

            $textual = [
                'application/json', 'application/xml', 'application/xhtml+xml',
                'application/javascript', 'application/ld+json',
                'application/rss+xml', 'application/atom+xml', 'image/svg+xml',
            ];
            if (in_array($type, $textual, true)) {
                return false;
            }

            return str_starts_with($type, 'application/')
                || str_starts_with($type, 'image/')
                || str_starts_with($type, 'audio/')
                || str_starts_with($type, 'video/')
                || str_starts_with($type, 'font/');
        }

        // No Content-Type: sniff a few common binary signatures.
        return str_starts_with($body, '%PDF')            // PDF
            || str_starts_with($body, "PK\x03\x04")      // ZIP / office
            || str_starts_with($body, "\x89PNG\x0d\x0a") // PNG
            || str_starts_with($body, 'GIF8')            // GIF
            || str_starts_with($body, "\xFF\xD8\xFF")    // JPEG
            || str_starts_with($body, '%!PS');           // PostScript
    }

    /**
     * Parse Set-Cookie response headers into a name => value map.
     *
     * @param array $setCookieLines Raw Set-Cookie header values.
     * @return array<string, string>
     */
    protected static function parseSetCookies(array $setCookieLines): array
    {
        $cookies = [];

        foreach ($setCookieLines as $line) {
            // First "name=value" pair of each Set-Cookie line (ignore attributes).
            if (preg_match('/^\s*([^=;\s]+)=([^;]*)/', (string) $line, $m)) {
                $cookies[$m[1]] = $m[2];
            }
        }

        return $cookies;
    }
}
