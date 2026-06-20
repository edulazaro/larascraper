<?php

namespace EduLazaro\Larascraper;

use Symfony\Component\DomCrawler\Crawler;
use EduLazaro\Larascraper\Runners\PuppeteerRunner;
use ReflectionMethod;
use LogicException;
use Throwable;

/*
|--------------------------------------------------------------------------
| Browser actions
|--------------------------------------------------------------------------
| The action methods (click, type, wait, waitForSelector, scroll, ...) build
| an ordered list that Puppeteer runs in a single browser session, after
| navigating to the URL and before grabbing the final HTML. The waits happen
| inside Node (where the page is alive), not in PHP. PHP only describes the
| recipe.
*/

abstract class Scraper
{
    protected string $url;
    protected ?string $proxy = null;
    protected ?string $proxyUser = null;
    protected ?string $proxyPass = null;
    protected array $headers = [];
    protected int $timeout = 20000;
    protected array $actions = [];
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
     * Click an element matching the CSS selector (waits for it first).
     *
     * @param string $selector CSS selector to click.
     * @param bool $waitForNavigation Set true when the click triggers a page
     *        load/navigation, so the wait is armed before the click (avoids a
     *        race). For clicks that only update the DOM, leave it false.
     */
    public function click(string $selector, bool $waitForNavigation = false): static
    {
        $action = ['type' => 'click', 'selector' => $selector];

        if ($waitForNavigation) {
            $action['waitForNavigation'] = true;
        }

        $this->actions[] = $action;
        return $this;
    }

    /**
     * Click an element and wait for the resulting navigation to finish.
     */
    public function clickAndWait(string $selector): static
    {
        return $this->click($selector, true);
    }

    /**
     * Type text into an input matching the CSS selector (waits for it first).
     */
    public function type(string $selector, string $text): static
    {
        $this->actions[] = ['type' => 'type', 'selector' => $selector, 'text' => $text];
        return $this;
    }

    /**
     * Select an option (by value) on a <select> matching the CSS selector.
     */
    public function select(string $selector, string $value): static
    {
        $this->actions[] = ['type' => 'select', 'selector' => $selector, 'value' => $value];
        return $this;
    }

    /**
     * Hover over an element matching the CSS selector.
     */
    public function hover(string $selector): static
    {
        $this->actions[] = ['type' => 'hover', 'selector' => $selector];
        return $this;
    }

    /**
     * Press a keyboard key (e.g. "Enter", "Tab", "Escape").
     *
     * @param string $key The key to press.
     * @param bool $waitForNavigation Set true when the key press submits a form
     *        / triggers navigation, so the wait is armed before the press.
     */
    public function press(string $key, bool $waitForNavigation = false): static
    {
        $action = ['type' => 'press', 'key' => $key];

        if ($waitForNavigation) {
            $action['waitForNavigation'] = true;
        }

        $this->actions[] = $action;
        return $this;
    }

    /**
     * Wait until an element matching the CSS selector appears in the DOM.
     */
    public function waitForSelector(string $selector): static
    {
        $this->actions[] = ['type' => 'waitForSelector', 'selector' => $selector];
        return $this;
    }

    /**
     * Wait for a navigation/reload to finish (e.g. after a click or submit).
     */
    public function waitForNavigation(): static
    {
        $this->actions[] = ['type' => 'waitForNavigation'];
        return $this;
    }

    /**
     * Wait a fixed amount of time, in milliseconds.
     */
    public function wait(int $ms): static
    {
        $this->actions[] = ['type' => 'wait', 'ms' => $ms];
        return $this;
    }

    /**
     * Scroll the page to the top or bottom (useful for lazy/infinite content).
     *
     * @param string $to "bottom" (default) or "top".
     */
    public function scroll(string $to = 'bottom'): static
    {
        $this->actions[] = ['type' => 'scroll', 'to' => $to];
        return $this;
    }

    /**
     * Convenience alias for scroll('bottom').
     */
    public function scrollToBottom(): static
    {
        return $this->scroll('bottom');
    }

    /**
     * Run the scraper and return parsed data.
     * 
     * @param array $params The attributes to validate and execute.
     * @return mixed
     */
    public function run(mixed ...$params): mixed
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

        return $this->handle(...$params);
    }
}
