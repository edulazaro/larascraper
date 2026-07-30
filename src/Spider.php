<?php

namespace EduLazaro\Larascraper;

use ArrayIterator;
use EduLazaro\Larascraper\Support\PendingScraper;
use EduLazaro\Larascraper\Support\ScraperResponse;
use EduLazaro\Larascraper\Support\Session;
use Fiber;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Iterator;
use IteratorIterator;
use Throwable;

/**
 * An IMPERATIVE orchestrator for a multi-page crawl, with a fiber-based
 * concurrent pool() primitive.
 *
 * Where a Scraper handles ONE page, a Spider walks a whole source. Unlike the
 * old declarative engine (targets()/collect()/drive()), the developer writes the
 * WHOLE orchestration inside handle(): log in at the top, decide what to fetch,
 * filter before pooling, and fan work out with pool(). A single shared Session
 * (created in run()) is threaded into every scraper the spider runs, so a login
 * or CSRF cookie established up front rides into every later request.
 *
 *   class BikeSpider extends Spider
 *   {
 *       protected int $concurrency = 20;  // 20 pages in flight
 *       protected int $delay = 250;       // ms between pool waves
 *
 *       public function handle(): mixed
 *       {
 *           // 1. Log in once; the shared Session captures the cookie.
 *           LoginScraper::make()->useSession($this->session)->handle();
 *
 *           // 2. Decide the work (params, not urls) and filter it.
 *           $ids = collect(range(1, 500))->reject(fn ($id) => Bike::where('remote_id', $id)->exists());
 *
 *           // 3. Fan out: run BikeScraper over each id, concurrently.
 *           $this->pool($ids, BikeScraper::class, $this->save(...));
 *
 *           return $ids->count();
 *       }
 *
 *       protected function save(mixed $data, mixed $id, ScraperResponse $response): void
 *       {
 *           if ($response->success) {
 *               Bike::updateOrCreate(['remote_id' => $id], $data);
 *           }
 *       }
 *   }
 *
 *   BikeSpider::run();
 *
 * pool() overlaps network I/O with PHP Fibers. Each item runs its scraper inside
 * a fiber; when a scraper fetches on an http-style driver, FetchBuilder suspends
 * the fiber and yields a fully-resolved request spec instead of blocking. The
 * scheduler gathers the currently-suspended fibers' specs into ONE wave and runs
 * them concurrently through Laravel Http::pool() (curl_multi under one handler,
 * fully fakeable with Http::fake), then resumes each fiber with its settled
 * response. A multi-step scraper's later step rides the same wave as another
 * item's earlier step; dependent steps within one item stay serial.
 */
abstract class Spider
{
    /**
     * HTTP statuses the concurrent path treats as retriable, matching the
     * sequential retry set in FetchBuilder::fetchBlocking().
     *
     * @var array<int, int>
     */
    protected const RETRIABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    /**
     * Nesting depth of active pool() schedulers. FetchBuilder consults this
     * (via schedulerActive()) to decide whether a fetch should suspend into a
     * wave instead of blocking. A counter, not a bool, so a nested pool() (a
     * collect() that itself pools) is handled.
     *
     * @var int
     */
    protected static int $schedulerDepth = 0;

    /** @var int Default number of scraper runs in flight per pool() wave. */
    protected int $concurrency = 10;

    /** @var int Milliseconds to pause between pool() waves (rate-limit); 0 = none. */
    protected int $delay = 0;

    /**
     * @var Session|null The shared cookie jar for this run.
     *
     * Created in run() and threaded into every scraper the spider runs (directly
     * or through pool()), so cookies accumulate across the whole crawl. Public so
     * handle() can hand it to a login scraper: `X::make()->useSession($this->session)`.
     */
    public ?Session $session = null;

    /**
     * The whole orchestration for this crawl. The developer writes everything
     * here: log in, decide the work, filter it, and fan it out with pool().
     *
     * @return mixed Whatever the crawl should report back (a count, a summary, ...).
     */
    abstract public function handle(): mixed;

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
     * Entry point: make an instance, give it a fresh shared Session, and run its
     * handle().
     *
     * @param mixed ...$params Constructor arguments for the spider.
     * @return mixed The value returned by handle().
     */
    public static function run(mixed ...$params): mixed
    {
        $spider = static::make(...$params);
        $spider->session = new Session();

        return $spider->handle();
    }

    /**
     * Whether a pool() scheduler is currently running. FetchBuilder checks this
     * (together with Fiber::getCurrent()) to route a fetch through the wave
     * scheduler instead of blocking.
     *
     * @return bool
     */
    public static function schedulerActive(): bool
    {
        return static::$schedulerDepth > 0;
    }

    /**
     * Run a scraper over each item CONCURRENTLY (up to $concurrency in flight),
     * then hand each finished result to $collect.
     *
     * Each $item is the PARAMS for one scraper run, NOT a url: an array item is
     * spread as the handle() arguments, any other value is passed as the single
     * argument. The scraper builds its own url from those params inside handle().
     *
     * $scraper is either a class-string or a configured PendingScraper (from
     * Scraper::with(...)) used as the template for every run, so its driver,
     * proxy, props and with() configuration apply to each item. A PendingScraper
     * template is cloned per item, so its state is never shared across fibers.
     * The shared Session is threaded into every run.
     *
     * $collect is any callable (an inline closure, or a method reference like
     * $this->save(...)) called once per item with ($response->data, $item,
     * $response). Per-item errors are isolated: a RequestException or any other
     * Throwable from a run is converted to a failed ScraperResponse (success
     * false, error set, data null) and still handed to $collect, so one bad item
     * never aborts the crawl. Inspect $response->success inside $collect.
     *
     * @param iterable $items The per-run params (arrays are spread, scalars passed whole).
     * @param string|PendingScraper $scraper The scraper class-string or configured template.
     * @param callable $collect Called per item as ($data, $item, $response).
     * @param int|null $concurrency Max runs in flight; defaults to $this->concurrency.
     * @return void
     */
    protected function pool(iterable $items, string|PendingScraper $scraper, callable $collect, ?int $concurrency = null): void
    {
        $limit = max(1, $concurrency ?? $this->concurrency);
        $iterator = $this->toIterator($items);
        $iterator->rewind();

        // slotId => ['fiber' => Fiber, 'item' => mixed, 'spec' => array, 'attempts' => int]
        $active = [];
        $slotId = 0;
        $firstWave = true;

        static::$schedulerDepth++;

        try {
            while (true) {
                // Refill free slots by starting fresh scraper fibers. A fiber
                // runs until it suspends (yielding a request spec) or finishes.
                while (count($active) < $limit && $iterator->valid()) {
                    $item = $iterator->current();
                    $iterator->next();

                    $fiber = $this->makeItemFiber($scraper, $item);
                    $spec = $fiber->start();

                    if ($fiber->isTerminated()) {
                        // Finished without an async fetch (e.g. a pure
                        // computation, or an early return): collect now, keep the
                        // slot free for the next item.
                        $this->collectResult($collect, $fiber->getReturn(), $item);
                        continue;
                    }

                    $active[$slotId] = ['fiber' => $fiber, 'item' => $item, 'spec' => $spec, 'attempts' => 1];
                    $slotId++;
                }

                if (empty($active)) {
                    break;
                }

                // One wave = the specs of every currently-suspended fiber, run
                // concurrently. A multi-step scraper's later step and another
                // item's first step share the wave.
                $specs = [];

                foreach ($active as $id => $slot) {
                    $specs[$id] = $slot['spec'];
                }

                if (!$firstWave && $this->delay > 0) {
                    usleep($this->delay * 1000);
                }

                $firstWave = false;

                $results = $this->runWave($specs);

                // Settle each slot. A retriable result (a connection failure, or
                // a response with a retriable status) whose per-request attempt
                // budget is not yet exhausted is NOT resumed into its fiber: the
                // slot stays active with the SAME spec so the next wave re-issues
                // it, and its attempt counter is bumped. This is the scheduler's
                // equivalent of the sequential retry loop in
                // FetchBuilder::fetchBlocking() (a pooled ->retry() is ignored
                // because pooled requests are async), so the concurrent and
                // blocking paths retry the SAME set. Otherwise the fiber is
                // resumed with the settled result: a fiber that finishes is
                // collected and frees its slot; one that suspends again carries a
                // new spec (and a fresh attempt budget) into the next wave.
                $retrySleep = 0;

                foreach ($active as $id => $slot) {
                    $result = $results[$id] ?? null;

                    if ($this->shouldRetry($result) && $slot['attempts'] < (int) $slot['spec']['maxRetries']) {
                        $active[$id]['attempts']++;
                        $retrySleep = max($retrySleep, (int) $slot['spec']['retryDelay']);
                        continue;
                    }

                    $next = $slot['fiber']->resume($result);

                    if ($slot['fiber']->isTerminated()) {
                        $this->collectResult($collect, $slot['fiber']->getReturn(), $slot['item']);
                        unset($active[$id]);
                    } else {
                        $active[$id]['spec'] = $next;
                        $active[$id]['attempts'] = 1;
                    }
                }

                // Honour the per-request retry delay (seconds, matching the
                // sequential path's sleep()) before the next wave re-issues the
                // retried requests.
                if ($retrySleep > 0) {
                    sleep($retrySleep);
                }
            }
        } finally {
            static::$schedulerDepth--;
        }
    }

    /**
     * Build the fiber that runs one item's scraper.
     *
     * The item's params are spread into handle() (an array item) or passed whole
     * (any other value). The shared Session is threaded in. Any throw is caught
     * and turned into a failed ScraperResponse, so per-item isolation holds: the
     * fiber ALWAYS returns a ScraperResponse for collect() to inspect.
     *
     * @param string|PendingScraper $template The scraper class-string or configured template.
     * @param mixed $item The per-run params.
     * @return Fiber
     */
    protected function makeItemFiber(string|PendingScraper $template, mixed $item): Fiber
    {
        return new Fiber(function () use ($template, $item): ScraperResponse {
            $params = is_array($item) ? $item : [$item];

            try {
                $scraper = $this->resolveScraper($template);

                if ($this->session !== null) {
                    $scraper->useSession($this->session);
                }

                return $scraper->handleToResponse($params);
            } catch (Throwable $e) {
                return new ScraperResponse(data: null, success: false, error: $e->getMessage());
            }
        });
    }

    /**
     * Resolve a fresh scraper instance for one item.
     *
     * A class-string is made through the container (fresh per item). A configured
     * PendingScraper template is cloned, so its configuration applies but its
     * mutable request state is never shared across concurrent fibers.
     *
     * @param string|PendingScraper $template The scraper class-string or configured template.
     * @return Scraper
     */
    protected function resolveScraper(string|PendingScraper $template): Scraper
    {
        if ($template instanceof PendingScraper) {
            return $template->fresh();
        }

        return $template::make();
    }

    /**
     * Execute one wave of request specs concurrently through Http::pool() and
     * return the settled results keyed by the same slot id.
     *
     * Each spec is fully resolved by FetchBuilder (url, method, headers, timeout,
     * session-merged cookies, proxy, basic auth, body), so this only translates
     * it onto one pooled request. Retry is NOT configured here: a pooled request
     * is async (Http\Client\Pool::as() returns an async request), so a per-request
     * ->retry() is ignored by Laravel. Retry is handled one level up, by the pool()
     * scheduler, which re-issues a retriable settled result in a later wave (see
     * shouldRetry()). Each request is left to settle without ->throw(), so a
     * non-2xx comes back as a Response for normalization and a connection failure
     * comes back as a Throwable in the results array.
     *
     * @param array<int, array> $specs slotId => request spec.
     * @return array<int, mixed> slotId => settled Response (or Throwable on a connection failure).
     */
    protected function runWave(array $specs): array
    {
        return Http::pool(function ($pool) use ($specs) {
            foreach ($specs as $key => $spec) {
                $request = $pool->as((string) $key)
                    ->withHeaders($spec['headers'])
                    ->timeout((int) max(1, ceil($spec['timeout'] / 1000)));

                if (!empty($spec['cookies'])) {
                    $request->withCookies($spec['cookies'], $spec['cookieDomain']);
                }

                if ($spec['proxy']) {
                    $request->withOptions(['proxy' => $spec['proxy']]);
                }

                if ($spec['proxyUser'] !== null && $spec['proxyPass'] !== null) {
                    $request->withBasicAuth($spec['proxyUser'], $spec['proxyPass']);
                }

                if ($spec['method'] === 'GET') {
                    $request->get($spec['url']);
                } else {
                    $spec['bodyFormat'] === 'json' ? $request->asJson() : $request->asForm();
                    $payload = is_array($spec['body']) ? $spec['body'] : (array) ($spec['body'] ?? []);
                    $request->{strtolower($spec['method'])}($spec['url'], $payload);
                }
            }
        });
    }

    /**
     * Whether a settled wave result should be retried in a later wave rather than
     * resumed into its fiber: a connection-level failure (any Throwable the pool
     * returned in place of a response), or a response whose status is in
     * RETRIABLE_STATUSES. This mirrors the sequential retry loop in
     * FetchBuilder::fetchBlocking(), which retries on the same status set and on
     * any thrown transport error, so the concurrent and blocking paths retry the
     * SAME set. A successful or otherwise non-retriable response resolves the slot.
     *
     * @param mixed $result The settled Http::pool result (Response or Throwable).
     * @return bool
     */
    protected function shouldRetry(mixed $result): bool
    {
        if ($result instanceof Response) {
            return in_array($result->status(), static::RETRIABLE_STATUSES, true);
        }

        return $result instanceof Throwable;
    }

    /**
     * Hand one finished item's result to the collector as ($data, $item,
     * $response). The fiber always returns a ScraperResponse, so success/error
     * are inspectable there.
     *
     * @param callable $collect The developer's per-item collector.
     * @param ScraperResponse $response The scraper-level result.
     * @param mixed $item The params that produced it.
     * @return void
     */
    protected function collectResult(callable $collect, ScraperResponse $response, mixed $item): void
    {
        $collect($response->data, $item, $response);
    }

    /**
     * Normalize any iterable of items into a rewindable Iterator the scheduler
     * can pull from lazily.
     *
     * @param iterable $items The items to crawl.
     * @return Iterator
     */
    protected function toIterator(iterable $items): Iterator
    {
        if (is_array($items)) {
            return new ArrayIterator($items);
        }

        if ($items instanceof Iterator) {
            return $items;
        }

        return new IteratorIterator($items);
    }
}
