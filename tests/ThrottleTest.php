<?php

namespace EduLazaro\Larascraper\Tests;

use EduLazaro\Larascraper\Exceptions\ThrottledException;
use EduLazaro\Larascraper\Support\Throttle;
use Illuminate\Support\Facades\Cache;

/**
 * Covers pacing and proxy lockout: the escalation of a repeatedly refused proxy,
 * the reset on success, and the guarantee that an unconfigured key behaves as if
 * the feature did not exist.
 *
 * Time and sleeping are stubbed (see FakeClockThrottle) so the suite neither
 * waits nor depends on the wall clock.
 */
class ThrottleTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        FakeClockThrottle::$offset = 0;
        config(['larascraper.throttle' => [
            'shop.listing' => ['interval' => 10, 'lock_base' => 120, 'lock_max' => 600],
        ]]);
    }

    public function test_an_unconfigured_key_never_waits(): void
    {
        $throttle = new FakeClockThrottle('nothing.configured');

        $this->assertFalse($throttle->configured());
        $this->assertSame(0, $throttle->pace());
        $this->assertSame([], $throttle->state(), 'An unconfigured key must not touch the cache.');
    }

    public function test_the_first_request_goes_out_immediately(): void
    {
        $throttle = new FakeClockThrottle('shop.listing');

        $this->assertSame(0, $throttle->pace());
    }

    public function test_the_next_request_waits_for_the_interval(): void
    {
        $throttle = new FakeClockThrottle('shop.listing');
        $throttle->pace();

        // Four seconds later, six of the ten-second interval are still owed.
        $throttle->travel(4);

        $this->assertSame(6, $throttle->pace());
    }

    public function test_pacing_is_shared_between_instances(): void
    {
        // Two instances stand in for two processes: a queue worker and a web
        // request pacing against the same target.
        (new FakeClockThrottle('shop.listing'))->pace();

        $otro = new FakeClockThrottle('shop.listing');

        $this->assertSame(10, $otro->pace(), 'State must come from the cache, not from the object.');
    }

    /**
     * The reason a slot is reserved before sleeping rather than after reading.
     * These three instances stand in for three queue workers waking at the same
     * instant — the clock does not move between them — and they must come away
     * with three different departure times, not one shared by all three.
     */
    public function test_processes_arriving_together_get_different_slots(): void
    {
        $this->assertSame(0, (new FakeClockThrottle('shop.listing'))->pace());
        $this->assertSame(10, (new FakeClockThrottle('shop.listing'))->pace());
        $this->assertSame(20, (new FakeClockThrottle('shop.listing'))->pace());
    }

    public function test_a_slot_further_away_than_max_wait_is_refused(): void
    {
        config(['larascraper.throttle' => [
            'shop.listing' => ['interval' => 10, 'max_wait' => 25],
        ]]);

        (new FakeClockThrottle('shop.listing'))->pace();   // now
        (new FakeClockThrottle('shop.listing'))->pace();   // +10
        (new FakeClockThrottle('shop.listing'))->pace();   // +20

        $this->expectException(ThrottledException::class);

        (new FakeClockThrottle('shop.listing'))->pace();   // +30, past the ceiling
    }

    public function test_a_refused_slot_is_left_for_whoever_comes_next(): void
    {
        config(['larascraper.throttle' => [
            'shop.listing' => ['interval' => 10, 'max_wait' => 15],
        ]]);

        (new FakeClockThrottle('shop.listing'))->pace();   // now
        (new FakeClockThrottle('shop.listing'))->pace();   // +10

        try {
            (new FakeClockThrottle('shop.listing'))->pace();   // +20, refused
            $this->fail('The slot past max_wait should have been refused.');
        } catch (ThrottledException $e) {
            $this->assertSame(20, $e->seconds, 'The refusal should say how long the wait would have been.');
        }

        // Giving up must not cost the queue anything: had the slot been taken
        // before throwing, everyone behind would pay for a request never made.
        $this->clockAt(11);

        $this->assertSame(9, (new FakeClockThrottle('shop.listing'))->pace());
    }

    public function test_without_max_wait_it_waits_however_long_it_takes(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $wait = (new FakeClockThrottle('shop.listing'))->pace();
        }

        $this->assertSame(190, $wait, 'With no ceiling configured, nothing is ever refused.');
    }

    /** Move the shared clock without needing an instance to do it. */
    private function clockAt(int $seconds): void
    {
        FakeClockThrottle::$offset = $seconds;
    }

    public function test_a_proxy_is_available_until_it_is_refused(): void
    {
        $throttle = new FakeClockThrottle('shop.listing');

        $this->assertTrue($throttle->available('203.0.113.10:8080'));

        $throttle->lockOut('203.0.113.10:8080');

        $this->assertFalse($throttle->available('203.0.113.10:8080'));
        $this->assertSame(['203.0.113.10:8080'], $throttle->lockedOut());
    }

    public function test_a_lockout_frees_up_when_its_time_passes(): void
    {
        $throttle = new FakeClockThrottle('shop.listing');
        $throttle->lockOut('203.0.113.10:8080');

        $throttle->travel(119);
        $this->assertFalse($throttle->available('203.0.113.10:8080'));

        $throttle->travel(2);
        $this->assertTrue($throttle->available('203.0.113.10:8080'));
    }

    public function test_lockouts_do_not_spill_onto_other_proxies(): void
    {
        $throttle = new FakeClockThrottle('shop.listing');
        $throttle->lockOut('203.0.113.10:8080');

        $this->assertTrue($throttle->available('203.0.113.11:8080'));
        $this->assertTrue($throttle->available(Throttle::DIRECT));
    }

    public function test_lockouts_do_not_spill_onto_other_keys(): void
    {
        config(['larascraper.throttle.shop.download' => ['interval' => 3, 'lock_base' => 60]]);

        (new FakeClockThrottle('shop.listing'))->lockOut('203.0.113.10:8080');

        // The same address, refused by the listing endpoint, is still fine for the
        // download endpoint — which is the reason keys are not hosts.
        $this->assertTrue((new FakeClockThrottle('shop.download'))->available('203.0.113.10:8080'));
    }

    public function test_each_refusal_doubles_the_wait_up_to_the_ceiling(): void
    {
        $throttle = new FakeClockThrottle('shop.listing');

        $this->assertSame(120, $throttle->lockOut('203.0.113.10:8080'));
        $this->assertSame(240, $throttle->lockOut('203.0.113.10:8080'));
        $this->assertSame(480, $throttle->lockOut('203.0.113.10:8080'));

        // lock_max is 600 here, so the doubling stops there instead of reaching 960.
        $this->assertSame(600, $throttle->lockOut('203.0.113.10:8080'));
        $this->assertSame(600, $throttle->lockOut('203.0.113.10:8080'));
    }

    public function test_success_clears_the_lockout_and_its_escalation(): void
    {
        $throttle = new FakeClockThrottle('shop.listing');
        $throttle->lockOut('203.0.113.10:8080');
        $throttle->lockOut('203.0.113.10:8080');

        $throttle->succeeded('203.0.113.10:8080');

        $this->assertTrue($throttle->available('203.0.113.10:8080'));

        // Back to the base wait: a proxy that recovers must not carry the penalty
        // of a bad afternoon into its next refusal.
        $this->assertSame(120, $throttle->lockOut('203.0.113.10:8080'));
    }

    public function test_success_on_a_healthy_proxy_changes_nothing(): void
    {
        $throttle = new FakeClockThrottle('shop.listing');
        $throttle->lockOut('203.0.113.10:8080');

        $throttle->succeeded('203.0.113.99:9999');

        $this->assertSame(['203.0.113.10:8080'], $throttle->lockedOut());
    }

    public function test_the_direct_exit_is_locked_out_like_any_other(): void
    {
        $throttle = new FakeClockThrottle('shop.listing');
        $throttle->lockOut(Throttle::DIRECT);

        $this->assertFalse($throttle->available(Throttle::DIRECT));
        $this->assertSame([Throttle::DIRECT], $throttle->lockedOut());
    }

    public function test_it_reports_how_long_until_a_proxy_frees_up(): void
    {
        $throttle = new FakeClockThrottle('shop.listing');
        $throttle->lockOut('203.0.113.10:8080');
        $throttle->lockOut('203.0.113.11:8080');
        $throttle->lockOut('203.0.113.11:8080');   // 240s, frees up later

        $throttle->travel(20);

        $this->assertSame(100, $throttle->nextFreeIn(['203.0.113.10:8080', '203.0.113.11:8080']));
    }

    public function test_a_healthy_proxy_means_no_wait_at_all(): void
    {
        $throttle = new FakeClockThrottle('shop.listing');
        $throttle->lockOut('203.0.113.10:8080');

        $this->assertSame(0, $throttle->nextFreeIn(['203.0.113.10:8080', '203.0.113.11:8080']));
    }

    public function test_forget_wipes_the_target_clean(): void
    {
        $throttle = new FakeClockThrottle('shop.listing');
        $throttle->pace();
        $throttle->lockOut('203.0.113.10:8080');

        $throttle->forget();

        $this->assertSame([], $throttle->state());
        $this->assertTrue($throttle->available('203.0.113.10:8080'));
    }
}

/**
 * Throttle with a clock the test drives and a sleep that does not sleep, so the
 * suite can cover waits of minutes without taking any.
 */
class FakeClockThrottle extends Throttle
{
    /** Shared so two instances see the same "now", as two processes would. */
    public static int $offset = 0;

    private int $start;

    public function __construct(string $key)
    {
        parent::__construct($key);

        $this->start = 1786300000;
    }

    public function travel(int $seconds): void
    {
        self::$offset += $seconds;
    }

    protected function now(): int
    {
        return $this->start + self::$offset;
    }

    protected function sleep(int $seconds): void
    {
        // Time only moves when a test says so.
    }
}
