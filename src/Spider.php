<?php

namespace EduLazaro\Larascraper;

use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Support\Session;
use LogicException;
use Throwable;

/**
 * Orchestrates a crawl over many targets, running one unit Scraper per target
 * while threading a single shared Session (cookie jar) through the whole run.
 *
 * Where a Scraper handles ONE page, a Spider walks a whole source: it yields
 * targets lazily, runs the configured $scraper against each, and hands the
 * result to a collect() hook to persist. Because every unit Scraper is given the
 * SAME Session, a login or a CSRF cookie established on the first target is
 * carried into every request that follows.
 *
 * Session threading needs a driver that carries outbound cookies, so the unit
 * Scraper must use the 'http' driver (see Runner::supportsCookies()). On the
 * default 'browser' driver the jar is a documented no-op, so the crawl still
 * runs but cookies are not replayed between targets.
 *
 *   class BikeScraper extends Scraper
 *   {
 *       protected string $driver = 'http'; // required to thread the Session
 *   }
 *
 *   class BikeSpider extends Spider
 *   {
 *       protected string $scraper = BikeScraper::class;
 *       protected int $delay = 250; // ms between requests
 *
 *       protected function targets(): iterable
 *       {
 *           foreach (range(1, 100) as $id) {
 *               yield "https://shop.test/bikes/{$id}";
 *           }
 *       }
 *
 *       protected function collect(mixed $data, string $url, ScraperResponse $response): void
 *       {
 *           Bike::updateOrCreate(['url' => $url], $data);
 *       }
 *   }
 *
 *   BikeSpider::run();
 *
 * The engine resolves each unit Scraper through make() (so container
 * dependencies are injected) and drives it through handleToResponse(), so the
 * same wrapping/fail() rules that apply to Scraper::run() apply here too: a
 * ScrapeException folds into a failed ScraperResponse, while a request-level
 * failure surfaces as a thrown exception routed to onError().
 */
abstract class Spider
{
    /** @var class-string<Scraper> The unit scraper class run once per target. */
    protected string $scraper;

    /** @var int Milliseconds to wait between requests (rate-limit); 0 = no delay. */
    protected int $delay = 0;

    /**
     * Yield the target URLs to crawl, lazily.
     *
     * Return a generator (or any iterable) so a large or open-ended crawl never
     * has to materialize the full list up front.
     *
     * @return iterable<string>
     */
    abstract protected function targets(): iterable;

    /**
     * Per-item hook: handle the result of one target. Override to persist.
     *
     * @param mixed $data The scrape data (ScraperResponse::$data).
     * @param string $url The target URL that produced it.
     * @param ScraperResponse $response The full scraper-level response.
     * @return void
     */
    protected function collect(mixed $data, string $url, ScraperResponse $response): void
    {
    }

    /**
     * Optional hook to establish the session once before the crawl begins.
     *
     * Override to perform a login or fetch a CSRF token so every target inherits
     * the resulting cookies. The same Session is threaded into every unit Scraper.
     *
     * @param Session $session The shared cookie jar for this run.
     * @return void
     */
    protected function bootSession(Session $session): void
    {
    }

    /**
     * Incremental/resume filter: return false to skip a target.
     *
     * Override to skip URLs already stored, so a re-run only fetches what is new.
     *
     * @param string $url The candidate target URL.
     * @return bool
     */
    protected function shouldVisit(string $url): bool
    {
        return true;
    }

    /**
     * Per-item error hook: called when a target throws.
     *
     * A request-level failure (RequestException) or any other Throwable raised
     * while handling a target is routed here instead of aborting the crawl.
     *
     * @param string $url The target URL that failed.
     * @param Throwable $e The exception raised.
     * @return void
     */
    protected function onError(string $url, Throwable $e): void
    {
    }

    /**
     * Create a spider instance through Laravel's service container, so
     * constructor dependencies are resolved.
     *
     * @param mixed ...$params Constructor arguments.
     * @return static
     */
    public static function make(mixed ...$params): static
    {
        return app(static::class, $params);
    }

    /**
     * Entry point: make an instance and run its crawl engine.
     *
     * @param mixed ...$params Constructor arguments for the spider.
     * @return void
     */
    public static function run(mixed ...$params): void
    {
        static::make(...$params)->drive();
    }

    /**
     * The crawl engine: one Session for the whole run, one unit Scraper per
     * target, with the incremental filter, error hook and delay applied.
     *
     * @return void
     */
    protected function drive(): void
    {
        // Fail fast on misconfiguration: a subclass that forgets to declare
        // $scraper would otherwise raise an uninitialized-typed-property Error
        // INSIDE the per-target try below, where drive()'s catch(Throwable)
        // would swallow it into onError() for every target and mask the bug.
        if (!isset($this->scraper)) {
            throw new LogicException(
                static::class . ' must define a protected string $scraper class-string.'
            );
        }

        $session = new Session();
        $this->bootSession($session);

        foreach ($this->targets() as $url) {
            if (!$this->shouldVisit($url)) {
                continue;
            }

            try {
                $scraper = ($this->scraper)::make()->useSession($session);
                $response = $scraper->handleToResponse([$url]);

                $this->collect($response->data, $url, $response);
            } catch (Throwable $e) {
                $this->onError($url, $e);
            }

            if ($this->delay > 0) {
                usleep($this->delay * 1000);
            }
        }
    }
}
