<?php

namespace EduLazaro\Larascraper;

use Symfony\Component\DomCrawler\Crawler;
use EduLazaro\Larascraper\Runners\PuppeteerRunner;

abstract class Scraper
{
    protected string $url;
    protected ?string $proxy = null;
    protected ?string $proxyUser = null;
    protected ?string $proxyPass = null;
    protected array $headers = [];
    protected int $timeout = 20000;

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
     * Run the scraper and return parsed data.
     */
    public function run(): array
    {
        $runner = PuppeteerRunner::on($this->url)
            ->timeout($this->timeout)
            ->withHeaders($this->headers);

        if ($this->proxy) {
            $runner->proxy($this->proxy);
        }

        if ($this->proxyUser && $this->proxyPass) {
            $runner->authenticate($this->proxyUser, $this->proxyPass);
        }

        $html = $runner->run();

        $crawler = new Crawler($html);

        return $this->handle($crawler);
    }

    /**
     * You must override this in the concrete scraper.
     */
    abstract protected function handle(Crawler $crawler): array;
}
