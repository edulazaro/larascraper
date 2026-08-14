<?php

namespace EduLazaro\Larascraper\Tests\Support;

use EduLazaro\Larascraper\Contracts\Runner;
use LogicException;

/**
 * A test Runner that mirrors the browser driver's cookie stance without any I/O.
 *
 * It reports supportsCookies() === false and, exactly like PuppeteerRunner,
 * THROWS if any outbound cookie reaches cookies(). It records what it was handed
 * and returns a canned successful response carrying a Set-Cookie, so a test can
 * prove a shared Session is skipped on a non-cookie driver: no throw on the way
 * in (the jar is never merged into the outbound cookies) and nothing accumulated
 * on the way out (the response cookies are never stored back).
 */
class NonCookieRunner implements Runner
{
    /** @var array The cookies handed to cookies() on the last run. */
    public static array $received = [];

    /** @var string The URL the runner was initialized with. */
    protected string $url = '';

    /**
     * Reset the recorded state between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        static::$received = [];
    }

    public static function on(string $url): static
    {
        $runner = new static();
        $runner->url = $url;
        return $runner;
    }

    public function authenticate(string $user, string $password): static
    {
        return $this;
    }

    public function proxy(string $proxy): static
    {
        return $this;
    }

    // NO userAgent() here, deliberately: this doubles as the guard for a custom
    // runner written before that capability existed. It once WAS declared on the
    // Runner interface, and this class is where the suite first said so — with a
    // fatal "must implement the remaining methods", which is exactly what every
    // third-party runner would have hit on upgrading. Adding the method silenced
    // the messenger; leaving it out keeps the warning working.

    public function withHeaders(array $headers): static
    {
        return $this;
    }

    public function withActions(array $actions): static
    {
        return $this;
    }

    public function timeout(int $ms): static
    {
        return $this;
    }

    public function method(string $method): static
    {
        return $this;
    }

    public function body(mixed $body, string $format = 'form'): static
    {
        return $this;
    }

    /**
     * Record the cookies and reject any non-empty set, mirroring the browser
     * driver so a leaked Session cookie surfaces as a loud failure.
     *
     * @param array       $cookies
     * @param string|null $domain
     * @throws LogicException If any cookie is provided.
     * @return static
     */
    public function cookies(array $cookies, ?string $domain = null): static
    {
        static::$received = $cookies;

        if (!empty($cookies)) {
            throw new LogicException('NonCookieRunner does not accept outbound cookies.');
        }

        return $this;
    }

    public function supportsCookies(): bool
    {
        return false;
    }

    /**
     * Return a canned successful response that carries a Set-Cookie, so a test
     * can assert the jar does NOT accumulate it on a non-cookie driver.
     *
     * @return array
     */
    public function run(): array
    {
        return [
            'success' => true,
            'status' => 200,
            'html' => '<html><title>stub</title></html>',
            'error' => null,
            'file' => null,
            'contentType' => 'text/html',
            'cookies' => ['NEW' => 'fromresponse'],
        ];
    }
}
