<?php

namespace EduLazaro\Larascraper\Runners;

use EduLazaro\Larascraper\Contracts\Runner;
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
    protected int $timeout = 20000;

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
     * Set browser actions. Not supported in HTTP mode.
     *
     * @param array $actions List of action descriptors.
     * @throws LogicException If any action is provided.
     * @return static
     */
    public function withActions(array $actions): static
    {
        if (!empty($actions)) {
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
     * Run the HTTP request and return the normalized result array.
     *
     * @return array{success: bool, status: int, html: ?string, error: ?string, file: ?string, contentType: ?string}
     */
    public function run(): array
    {
        try {
            $request = Http::withHeaders($this->headers)
                ->timeout((int) max(1, ceil($this->timeout / 1000)));

            if ($this->proxy) {
                $request = $request->withOptions(['proxy' => $this->proxy]);
            }

            if ($this->user !== null && $this->password !== null) {
                $request = $request->withBasicAuth($this->user, $this->password);
            }

            $response = $request->get($this->url);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'html' => $response->body(),
                'error' => $response->successful() ? null : "HTTP {$response->status()}",
                'file' => null,
                'contentType' => $response->header('Content-Type') ?: null,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 0,
                'html' => null,
                'error' => $e->getMessage(),
                'file' => null,
                'contentType' => null,
            ];
        }
    }
}
