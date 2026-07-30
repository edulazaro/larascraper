<?php

namespace EduLazaro\Larascraper\Tests\Concerns;

use EduLazaro\Larascraper\Support\Condition;
use EduLazaro\Larascraper\Tests\BaseTestCase;
use EduLazaro\Larascraper\Tests\Support\TestScraper;

/**
 * The action chain moved off the Scraper and onto the FetchBuilder in v3.
 *
 * `$this->scrape($url)` (a public instance method) returns a FetchBuilder that
 * `use`s the {@see \EduLazaro\Larascraper\Concerns\BuildsActions} trait, so the
 * exact same descriptors are produced. These tests reach the FetchBuilder via
 * `TestScraper::make()->scrape(...)` and assert the emitted action arrays are
 * byte-for-byte identical to the v2 behaviour.
 */
class BuildsActionsTest extends BaseTestCase
{
    public function test_goto_attr_builds_the_expected_action(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->gotoAttr('object[type*="pdf"]', 'data')
            ->getActions();

        $this->assertSame([[
            'type' => 'gotoAttr',
            'selector' => 'object[type*="pdf"]',
            'attr' => 'data',
            'waitUntil' => 'networkidle2',
        ]], $actions);
    }

    public function test_goto_attr_defaults_to_href(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->gotoAttr('a.next')
            ->getActions();

        $this->assertSame('href', $actions[0]['attr']);
    }

    public function test_reload_builds_the_expected_action(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->reload()
            ->getActions();

        $this->assertSame([['type' => 'reload', 'waitUntil' => 'networkidle2']], $actions);
    }

    public function test_visit_builds_a_goto_action(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->visit('https://example.com/viewer')
            ->getActions();

        $this->assertSame(
            [['type' => 'goto', 'url' => 'https://example.com/viewer', 'waitUntil' => 'networkidle2']],
            $actions
        );
    }

    public function test_visit_accepts_a_wait_until_override(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->visit('https://example.com/viewer', 'domcontentloaded')
            ->getActions();

        $this->assertSame('domcontentloaded', $actions[0]['waitUntil']);
    }

    public function test_capture_builds_the_expected_action(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->capture()
            ->getActions();

        $this->assertSame([['type' => 'capture', 'expect' => null, 'timeout' => null]], $actions);
    }

    public function test_capture_accepts_a_content_type_string(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->capture('application/pdf')
            ->getActions();

        $this->assertSame('application/pdf', $actions[0]['expect']);
    }

    public function test_capture_accepts_an_options_array(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->capture(['expect' => 'application/pdf', 'timeout' => 5000])
            ->getActions();

        $this->assertSame(
            [['type' => 'capture', 'expect' => 'application/pdf', 'timeout' => 5000]],
            $actions
        );
    }

    public function test_submit_builds_the_expected_action(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->submit('form')
            ->getActions();

        $this->assertSame([['type' => 'submit', 'formSelector' => 'form']], $actions);
    }

    public function test_submit_and_capture_still_builds_its_action(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->submitAndCapture('form', ['expect' => 'application/pdf'])
            ->getActions();

        $this->assertSame(
            [['type' => 'submitAndCapture', 'formSelector' => 'form', 'expect' => 'application/pdf']],
            $actions
        );
    }

    public function test_when_builds_a_conditional_action_with_then_and_else(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->when(
                Condition::selectorExists('#banner'),
                fn ($b) => $b->click('#accept'),
                fn ($b) => $b->reload(),
            )
            ->getActions();

        $this->assertSame('when', $actions[0]['type']);
        $this->assertSame(Condition::selectorExists('#banner'), $actions[0]['condition']);
        $this->assertSame([['type' => 'click', 'selector' => '#accept']], $actions[0]['then']);
        $this->assertSame([['type' => 'reload', 'waitUntil' => 'networkidle2']], $actions[0]['else']);
    }

    public function test_when_without_an_else_omits_the_else_branch(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->when(Condition::selectorMissing('.results'), fn ($b) => $b->reload())
            ->getActions();

        $this->assertArrayNotHasKey('else', $actions[0]);
    }

    public function test_repeat_until_clamps_max_and_delay_and_nests_the_body(): void
    {
        $actions = TestScraper::make()->scrape('https://example.com')
            ->repeatUntil(
                Condition::selectorMissing('#captcha'),
                fn ($b) => $b->click('#verify'),
                max: 0,       // clamped up to 1
                delay: -5,    // clamped up to 0
            )
            ->getActions();

        $this->assertSame('repeatUntil', $actions[0]['type']);
        $this->assertSame(Condition::selectorMissing('#captcha'), $actions[0]['condition']);
        $this->assertSame(1, $actions[0]['max']);
        $this->assertSame(0, $actions[0]['delay']);
        $this->assertSame([['type' => 'click', 'selector' => '#verify']], $actions[0]['body']);
    }

    public function test_condition_factories_build_their_descriptors(): void
    {
        $this->assertSame(['type' => 'selectorExists', 'selector' => '.a'], Condition::selectorExists('.a'));
        $this->assertSame(['type' => 'selectorMissing', 'selector' => '.b'], Condition::selectorMissing('.b'));
        $this->assertSame(['type' => 'textContains', 'text' => 'hi'], Condition::textContains('hi'));
        $this->assertSame(['type' => 'textContains', 'text' => 'hi', 'selector' => '.c'], Condition::textContains('hi', '.c'));
        $this->assertSame(['type' => 'urlContains', 'text' => '/ok'], Condition::urlContains('/ok'));
        $this->assertSame(['type' => 'captured'], Condition::captured());
    }
}
