<?php

namespace EduLazaro\Larascraper\Runners;

use Exception;

/**
 * Run a Puppeteer scraper using a Node script.
 */
class PuppeteerRunner
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
     * Set basic authentication credentials.
     * 
     * @param string $user Proxy username.
     * @param string $password Proxy password.
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
     * @param string $proxy Proxy address (e.g., IP:PORT).
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
     * Run the scraper script and return the HTML output.
     * 
     * @throws Exception If the scraper returns an error.
     * @return string The HTML content.
     */
    public function run(): string
    {
        $script = __DIR__ . '/../../resources/scraper.cjs';

        $args = [
            '--url=' . escapeshellarg($this->url),
        ];

        if ($this->proxy) {
            $args[] = '--proxy=' . escapeshellarg($this->proxy);
        }

        if ($this->user) {
            $args[] = '--user=' . escapeshellarg($this->user);
        }

        if ($this->password) {
            $args[] = '--pass=' . escapeshellarg($this->password);
        }

        if (!empty($this->headers)) {
            $jsonHeaders = json_encode($this->headers, JSON_THROW_ON_ERROR);
            $args[] = '--headers=' . escapeshellarg($jsonHeaders);
        }

        $cmd = 'node ' . escapeshellcmd($script) . ' ' . implode(' ', $args);

        $output = shell_exec($cmd);

        if (is_null($output)) {
            throw new Exception('Scraper failed to return any output.');
        }
        
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        if (isset($result['error'])) {
            throw new Exception('Scraper error: ' . $result['error']);
        }

        return $result['html'] ?? '';
    }
}
