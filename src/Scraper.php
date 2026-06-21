<?php

namespace EduLazaro\Larascraper;

use Symfony\Component\DomCrawler\Crawler;
use EduLazaro\Larascraper\Runners\PuppeteerRunner;
use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Concerns\BuildsActions;
use ReflectionMethod;
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

        while (++$attempt <= $this->maxRetries) {
            echo ("GETTING: {$this->url} (Attempt #{$attempt})\n");

            try {

                $runner = PuppeteerRunner::on($this->url)
                    ->timeout($this->timeout)
                    ->withHeaders($this->headers)
                    ->withActions($this->actions);

                if ($this->proxy) {
                    $runner->proxy($this->proxy);
                }

                if ($this->proxyUser && $this->proxyPass) {
                    $runner->authenticate($this->proxyUser, $this->proxyPass);
                }

                $response = $runner->run();

                $this->status = $response['status'] ?? 0;
                $this->success = $response['success'] ?? false;
                $this->error = $response['error'] ?? null;
                $this->html = $response['html'] ?? null;


                if ($this->success) {
                    break;
                }

                echo ("Error getting {$this->url} on attempt #{$attempt}: {$this->status}\n");

                if (!in_array($this->status, [408, 429, 500, 502, 503, 504])) {
                    break;
                }

            } catch (Throwable $e) {
                echo ("Error getting {$this->url} on attempt #{$attempt}: {$e->getMessage()}\n");

                $this->error = $e->getMessage();
                $this->success = false;
            }

            if ($attempt < $this->maxRetries) {
                echo ("Retrying in {$this->retryDelay} seconds...\n");
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
}
