<?php

namespace EduLazaro\Larascraper\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Per-target request pacing and proxy lockout.
 *
 * Sites that dislike being scraped rarely say so politely: they answer 403 and
 * stop serving that IP for a while. Two things keep a scraper welcome, and this
 * class holds both:
 *
 *   - PACING. Requests to the same target are spaced apart, across every process
 *     that runs them. Queue workers, web requests and CLI commands share one
 *     schedule instead of each keeping its own.
 *
 *   - LOCKOUT. When a proxy is refused, that proxy stops being used for that
 *     target for a while. The wait doubles each time it is refused again, and
 *     resets the moment it succeeds.
 *
 * Both are scoped by a THROTTLE KEY — a string the scraper chooses, not the host.
 * Hosts are too coarse: the same domain can serve one path happily while blocking
 * another, and a lockout earned by the blocked path should not stop the rest.
 * Scrapers that want to share a budget simply share a key.
 *
 * State lives in one cache entry per key, so a full picture costs a single read:
 *
 *     larascraper:throttle:cendoj.search
 *     {
 *         "_last": 1786309712,
 *         "203.0.113.10:8080": { "lock_time": 480, "lock_until": 1786310400 },
 *         "direct":            { "lock_time": 120, "lock_until": 1786309800 }
 *     }
 *
 * Absent proxies are healthy — nothing is written until something is refused.
 * The entry expires on its own once the last lockout has elapsed, which returns
 * the key to a clean slate without anyone sweeping it.
 *
 * Doing nothing is the default: with no configuration for a key, requests are
 * neither paced nor locked out, and the cache is never touched.
 */
class Throttle
{
    /** Cache entry holding the state for one throttle key. */
    private const PREFIX = 'larascraper:throttle:';

    /** Reserved member of that entry: when the last request went out. */
    private const LAST = '_last';

    /** Label for the exit that uses no proxy, so it can be locked out too. */
    public const DIRECT = 'direct';

    public function __construct(private string $key) {}

    /**
     * Wait until this target may be contacted again.
     *
     * Returns the seconds spent waiting. Callers that need to bound their own
     * runtime can use it; most can ignore it.
     */
    public function pace(): int
    {
        $interval = (int) $this->config('interval', 0);

        if ($interval <= 0) {
            return 0;
        }

        $state = $this->state();
        $last = (int) ($state[self::LAST] ?? 0);
        $wait = max(0, ($last + $interval) - $this->now());

        if ($wait > 0) {
            $this->sleep($wait);
        }

        $state[self::LAST] = $this->now();
        $this->store($state);

        return $wait;
    }

    /**
     * Proxies currently locked out for this target, as their labels.
     *
     * @return list<string>
     */
    public function lockedOut(): array
    {
        $now = $this->now();
        $out = [];

        foreach ($this->state() as $label => $entry) {
            if ($label === self::LAST || ! is_array($entry)) {
                continue;
            }

            if ((int) ($entry['lock_until'] ?? 0) > $now) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /** Whether this proxy may be used against this target right now. */
    public function available(string $label): bool
    {
        $entry = $this->state()[$label] ?? null;

        return ! is_array($entry) || (int) ($entry['lock_until'] ?? 0) <= $this->now();
    }

    /**
     * Seconds until the first locked-out proxy frees up, or 0 if any is free.
     * Only useful for telling a caller how long to wait.
     */
    public function nextFreeIn(array $labels): int
    {
        $now = $this->now();
        $waits = [];

        foreach ($labels as $label) {
            $entry = $this->state()[$label] ?? null;
            $waits[] = is_array($entry) ? max(0, (int) ($entry['lock_until'] ?? 0) - $now) : 0;
        }

        return $waits === [] ? 0 : (int) min($waits);
    }

    /**
     * Record that this proxy was refused: lock it out, twice as long as last time.
     *
     * The wait itself carries the escalation, so nothing counts attempts. A first
     * refusal waits `lock_base`; a proxy refused again while still remembered waits
     * double, up to `lock_max`. One that has been quiet long enough for its entry
     * to expire starts over at the base — which is the intent, since the site has
     * evidently stopped minding.
     */
    public function lockOut(string $label): int
    {
        $base = (int) $this->config('lock_base', 120);
        $max = (int) $this->config('lock_max', 3600);

        $state = $this->state();
        $previous = (int) ($state[$label]['lock_time'] ?? 0);
        $lockTime = min($previous > 0 ? $previous * 2 : $base, $max);

        $state[$label] = [
            'lock_time' => $lockTime,
            'lock_until' => $this->now() + $lockTime,
        ];

        $this->store($state);

        return $lockTime;
    }

    /**
     * Record that this proxy worked: forget it was ever refused.
     *
     * The whole entry goes, not just the deadline — a proxy that recovers should
     * not carry the penalty of a bad afternoon into its next refusal.
     */
    public function succeeded(string $label): void
    {
        $state = $this->state();

        if (! isset($state[$label])) {
            return;
        }

        unset($state[$label]);
        $this->store($state);
    }

    /** Everything known about this target. Handy in tests and when debugging. */
    public function state(): array
    {
        $state = Cache::get(self::PREFIX . $this->key, []);

        return is_array($state) ? $state : [];
    }

    /** Drop all pacing and lockouts for this target. */
    public function forget(): void
    {
        Cache::forget(self::PREFIX . $this->key);
    }

    /** Whether anything is configured for this key at all. */
    public function configured(): bool
    {
        return is_array($this->policy());
    }

    /**
     * Persist the state, keeping it alive as long as it still says something:
     * the longest lockout, or one pacing interval, whichever runs later.
     */
    private function store(array $state): void
    {
        $deadlines = [$this->now() + max((int) $this->config('interval', 0), 1)];

        foreach ($state as $label => $entry) {
            if ($label !== self::LAST && is_array($entry)) {
                $deadlines[] = (int) ($entry['lock_until'] ?? 0);
            }
        }

        $ttl = max(1, max($deadlines) - $this->now() + 10);

        Cache::put(self::PREFIX . $this->key, $state, $ttl);
    }

    /**
     * The policy for this key, or null when there is none.
     *
     * The whole table is read and indexed by hand rather than asking config() for
     * a dotted path: throttle keys are expected to look like 'shop.listing', and
     * config('larascraper.throttle.shop.listing') would go looking for a nested
     * ['shop']['listing'] instead of the literal key.
     */
    private function policy(): ?array
    {
        $table = function_exists('config') ? config('larascraper.throttle', []) : [];
        $policy = is_array($table) ? ($table[$this->key] ?? null) : null;

        return is_array($policy) ? $policy : null;
    }

    private function config(string $name, int $default): int
    {
        return (int) ($this->policy()[$name] ?? $default);
    }

    /** Wrapped so tests can run without actually waiting. */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    /** Wrapped so tests can move time forward. */
    protected function now(): int
    {
        return time();
    }
}
