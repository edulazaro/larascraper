<?php

namespace EduLazaro\Larascraper;

use Symfony\Component\DomCrawler\Crawler;
use EduLazaro\Larascraper\Contracts\Runner;
use EduLazaro\Larascraper\Runners\PuppeteerRunner;
use EduLazaro\Larascraper\Runners\HttpRunner;
use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Concerns\BuildsActions;
use ReflectionMethod;
use InvalidArgumentException;
use LogicException;
use Throwable;

abstract class Scraper
{
    use BuildsActions;

    protected string $url;
    protected ?string $proxy = null;
    protected ?string $proxyUser = null;
    protected ?string $proxyPass = null;
    protected array $headers = [];
    protected int $timeout = 20000;
    protected Crawler $crawler;

    /** HTTP method for the 'http' driver (browser driver is GET-only). */
    protected string $httpMethod = 'GET';
    protected mixed $body = null;
    protected string $bodyFormat = 'form';
    protected array $cookies = [];
    protected ?string $cookieDomain = null;

    /** Which runner to use: 'browser' (Puppeteer) or 'http' (plain HTTP). */
    protected string $driver = 'browser';

    /** Map of available drivers to their runner classes. */
    protected array $drivers = [
        'browser' => PuppeteerRunner::class,
        'http' => HttpRunner::class,
    ];

    public bool $success = false;
    public int $status = 0;
    public ?string $error = null;
    public ?string $html = null;

    protected int $maxRetries = 3;
    protected int $retryDelay = 15;

    /**
     * Create a new scraper instance for the given URL.
     *
     * @param string $url
     * @return static
     */
    public static function scrape(string $url): static
    {
        $instance = new static();
        $instance->url = $url;
        return $instance;
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
     * Choose the runner driver.
     *
     * @param string $driver 'browser' (Puppeteer, default) or 'http' (plain HTTP, no browser).
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
     * Set retry attempts and delay
     *
     * @param int $attempts The times to retry
     * @param int $seconds The time between attempts
     */
    public function retry(int $attempts, int $seconds): static
    {
        $this->maxRetries = $attempts;
        $this->retryDelay = $seconds;
        return $this;
    }

    /**
     * Run the scraper and return the result (status + parsed data).
     *
     * @param mixed ...$params Parameters passed through to handle().
     * @return ScraperResponse
     */
    public function run(mixed ...$params): ScraperResponse
    {
        if (!method_exists($this, 'handle')) {
            throw new LogicException("The scraper class " . static::class . " must implement a `handle` method.");
        }

        $attempt = 0;
        $response = [];

        // Build the runner once, before the retry loop. Configuration errors
        // (e.g. using actions with the 'http' driver) should fail fast rather
        // than be swallowed and retried, and there is no need to rebuild it on
        // every attempt — only the fetch itself is retried.
        /** @var Runner $runnerClass */
        $runnerClass = $this->drivers[$this->driver];

        $runner = $runnerClass::on($this->url)
            ->timeout($this->timeout)
            ->withHeaders($this->headers)
            ->withActions($this->actions)
            ->method($this->httpMethod)
            ->body($this->body, $this->bodyFormat)
            ->cookies($this->cookies, $this->cookieDomain);

        if ($this->proxy) {
            $runner->proxy($this->proxy);
        }

        if ($this->proxyUser && $this->proxyPass) {
            $runner->authenticate($this->proxyUser, $this->proxyPass);
        }

        while (++$attempt <= $this->maxRetries) {
            $this->log("GETTING: {$this->url} (Attempt #{$attempt})");

            try {

                $response = $runner->run();

                $this->status = $response['status'] ?? 0;
                $this->success = $response['success'] ?? false;
                $this->error = $response['error'] ?? null;
                $this->html = $response['html'] ?? null;


                if ($this->success) {
                    break;
                }

                $this->log("Error getting {$this->url} on attempt #{$attempt}: {$this->status}");

                if (!in_array($this->status, [408, 429, 500, 502, 503, 504])) {
                    break;
                }

            } catch (Throwable $e) {
                $this->log("Error getting {$this->url} on attempt #{$attempt}: {$e->getMessage()}");

                $this->error = $e->getMessage();
                $this->success = false;
            }

            if ($attempt < $this->maxRetries) {
                $this->log("Retrying in {$this->retryDelay} seconds...");
                sleep($this->retryDelay);
            }
        }

        $this->crawler = new Crawler($response['html'] ?? '');

        if (array_key_first($params) == 0) {

            $reflection = new ReflectionMethod($this, 'handle');
            $paramNames = [];

            foreach ($reflection->getParameters() as $index => $param) {
                $paramNames[$param->getName()] = $params[$index] ?? null;
            }

            $params = $paramNames;
        }

        $data = $this->handle(...$params);

        // A captured file/binary arrives base64-encoded from the scraper script.
        $file = isset($response['file']) ? base64_decode($response['file']) : null;

        return new ScraperResponse(
            success: $this->success,
            status: $this->status,
            error: $this->error,
            html: $this->html ?? '',
            data: $data,
            file: $file,
            contentType: $response['contentType'] ?? null,
        );
    }

    /**
     * Write a progress line to stdout, suppressed while running tests.
     */
    protected function log(string $message): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        echo $message . "\n";
    }
}
