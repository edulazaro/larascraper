<?php

namespace EduLazaro\Larascraper;

use Symfony\Component\DomCrawler\Crawler;
use EduLazaro\Larascraper\Runners\PuppeteerRunner;
use ReflectionMethod;
use LogicException;

abstract class Scraper
{
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
     * 
     * @param array $params The attributes to validate and execute.
     * @return mixed
     */
    public function run(mixed ...$params): mixed
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



        $response = $runner->run();

        print_r($response['html']);


        $this->status = $response['status'] ?? 0;
        $this->success = $response['success'] ?? false;
        $this->error = $response['error'] ?? null;

        $this->crawler = new Crawler($response['html'] ?? '');

        if (array_key_first($params) == 0) {

            $reflection = new ReflectionMethod($this, 'handle');
            $paramNames = [];

            foreach ($reflection->getParameters() as $index => $param) {
                $paramNames[$param->getName()] = $params[$index] ?? null;
            }

            $params = $paramNames;
        }

        if (method_exists($this, 'handle')) {
            return $this->handle(...$params);
        }

        throw new LogicException("The action class " . static::class . " must implement a `action` method.");
    }
}
