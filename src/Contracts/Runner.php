<?php

namespace EduLazaro\Larascraper\Contracts;

/**
 * Common contract for scraper runners.
 *
 * A runner fetches a URL and returns a normalized result array that
 * Scraper::run() consumes, regardless of the underlying transport
 * (headless browser, plain HTTP, etc.).
 *
 * The run() result must be an array with the following keys:
 *   - success     bool      Whether the fetch succeeded.
 *   - status      int       HTTP status code (0 if unknown).
 *   - html        ?string   The response body / page HTML.
 *   - error       ?string   Error message, or null on success.
 *   - file        ?string   Captured binary, base64-encoded, or null.
 *   - contentType ?string   Response Content-Type, or null.
 *   - cookies     array     Response cookies as a name => value map.
 */
interface Runner
{
    /**
     * Initialize the runner with a target URL.
     *
     * @param string $url The URL to scrape.
     * @return static
     */
    public static function on(string $url): static;

    /**
     * Set authentication credentials.
     *
     * @param string $user Username.
     * @param string $password Password.
     * @return static
     */
    public function authenticate(string $user, string $password): static;

    /**
     * Set a proxy server (IP:PORT or full URL).
     *
     * @param string $proxy Proxy address.
     * @return static
     */
    public function proxy(string $proxy): static;

    /**
     * Set request headers.
     *
     * @param array $headers Associative array of headers.
     * @return static
     */
    public function withHeaders(array $headers): static;

    /**
     * Set the ordered list of browser actions to run after navigation.
     *
     * @param array $actions List of action descriptors (type + params).
     * @return static
     */
    public function withActions(array $actions): static;

    /**
     * Set the timeout in milliseconds.
     *
     * @param int $ms Timeout duration in milliseconds.
     * @return static
     */
    public function timeout(int $ms): static;

    /**
     * Set the HTTP method (GET, POST, PUT, PATCH, DELETE).
     *
     * The "http" driver honours this; the "browser" driver only supports GET
     * navigation and throws otherwise.
     *
     * @param string $method HTTP verb.
     * @return static
     */
    public function method(string $method): static;

    /**
     * Set the request body for non-GET requests.
     *
     * @param mixed  $body   Payload (array for form/json), or null.
     * @param string $format 'form' (x-www-form-urlencoded) or 'json'.
     * @return static
     */
    public function body(mixed $body, string $format = 'form'): static;

    /**
     * Set request cookies (name => value).
     *
     * @param array       $cookies Associative array of cookie name => value.
     * @param string|null $domain  Cookie domain (defaults to the URL host).
     * @return static
     */
    public function cookies(array $cookies, ?string $domain = null): static;

    /**
     * Run the scraper and return the normalized result array.
     *
     * @return array{success: bool, status: int, html: ?string, error: ?string, file: ?string, contentType: ?string}
     */
    public function run(): array;
}
