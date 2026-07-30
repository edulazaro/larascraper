<?php

namespace EduLazaro\Larascraper\Support;

use EduLazaro\Larascraper\Scraper;

/**
 * The thin wrapper returned by Scraper::with().
 *
 * It exists to resolve a PHP constraint: a single method name cannot be both
 * static-callable as `Foo::run()` and preserve instance state through
 * `$configured->run()` (a static method invoked via `->` gets no `$this`, and
 * `__callStatic` does not fire for a declared instance method). So run()/with()
 * are STATIC on Scraper, and with() hands the configured instance to this
 * wrapper, whose own instance methods keep operating on that same instance:
 *
 *     ReportScraper::with(driver: 'http')   // -> PendingScraper
 *         ->cookies(['a' => 'b'])            // forwarded fluent setter -> $this
 *         ->run($url);                       // runs the configured instance
 */
class PendingScraper
{
    /**
     * @param Scraper $scraper The configured scraper instance.
     */
    public function __construct(protected Scraper $scraper)
    {
    }

    /**
     * Inject more parameters into the wrapped instance's properties.
     *
     * @param mixed ...$params Property values to inject (named or an assoc array).
     * @return static
     */
    public function with(mixed ...$params): static
    {
        $this->scraper->applyWith($params);

        return $this;
    }

    /**
     * Run the wrapped, configured instance's handle().
     *
     * @param mixed ...$params Positional, named, or array arguments for handle().
     * @return ScraperResponse
     */
    public function run(mixed ...$params): ScraperResponse
    {
        return $this->scraper->handleToResponse($params);
    }

    /**
     * A FRESH clone of the configured instance, carrying the same configuration
     * (driver, headers, proxy, tries, ...) but its own mutable request state.
     *
     * A Spider pool() uses this to give every item its own configured scraper,
     * so a template built with Scraper::with(...) is never shared (and its
     * $this->request never clobbered) across concurrent fibers.
     *
     * @return Scraper
     */
    public function fresh(): Scraper
    {
        return clone $this->scraper;
    }

    /**
     * Forward any other call to the wrapped scraper.
     *
     * When the scraper returns itself (a fluent setter), return $this so the
     * chain stays on the wrapper; otherwise return the raw value.
     *
     * @param string $method The method name.
     * @param array $arguments The call arguments.
     * @return mixed
     */
    public function __call(string $method, array $arguments): mixed
    {
        $result = $this->scraper->$method(...$arguments);

        return $result === $this->scraper ? $this : $result;
    }
}
