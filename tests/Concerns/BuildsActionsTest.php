<?php

namespace EduLazaro\Larascraper\Tests\Concerns;

use EduLazaro\Larascraper\Tests\BaseTestCase;
use EduLazaro\Larascraper\Tests\Support\TestScraper;

class BuildsActionsTest extends BaseTestCase
{
    public function test_goto_attr_builds_the_expected_action(): void
    {
        $actions = TestScraper::scrape('https://example.com')
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
        $actions = TestScraper::scrape('https://example.com')
            ->gotoAttr('a.next')
            ->getActions();

        $this->assertSame('href', $actions[0]['attr']);
    }

    public function test_reload_builds_the_expected_action(): void
    {
        $actions = TestScraper::scrape('https://example.com')
            ->reload()
            ->getActions();

        $this->assertSame([['type' => 'reload', 'waitUntil' => 'networkidle2']], $actions);
    }

    public function test_visit_builds_a_goto_action(): void
    {
        $actions = TestScraper::scrape('https://example.com')
            ->visit('https://example.com/viewer')
            ->getActions();

        $this->assertSame(
            [['type' => 'goto', 'url' => 'https://example.com/viewer', 'waitUntil' => 'networkidle2']],
            $actions
        );
    }

    public function test_visit_accepts_a_wait_until_override(): void
    {
        $actions = TestScraper::scrape('https://example.com')
            ->visit('https://example.com/viewer', 'domcontentloaded')
            ->getActions();

        $this->assertSame('domcontentloaded', $actions[0]['waitUntil']);
    }

    public function test_capture_builds_the_expected_action(): void
    {
        $actions = TestScraper::scrape('https://example.com')
            ->capture()
            ->getActions();

        $this->assertSame([['type' => 'capture', 'expect' => null, 'timeout' => null]], $actions);
    }

    public function test_capture_accepts_a_content_type_string(): void
    {
        $actions = TestScraper::scrape('https://example.com')
            ->capture('application/pdf')
            ->getActions();

        $this->assertSame('application/pdf', $actions[0]['expect']);
    }

    public function test_capture_accepts_an_options_array(): void
    {
        $actions = TestScraper::scrape('https://example.com')
            ->capture(['expect' => 'application/pdf', 'timeout' => 5000])
            ->getActions();

        $this->assertSame(
            [['type' => 'capture', 'expect' => 'application/pdf', 'timeout' => 5000]],
            $actions
        );
    }

    public function test_submit_builds_the_expected_action(): void
    {
        $actions = TestScraper::scrape('https://example.com')
            ->submit('form')
            ->getActions();

        $this->assertSame([['type' => 'submit', 'formSelector' => 'form']], $actions);
    }

    public function test_submit_and_capture_still_builds_its_action(): void
    {
        $actions = TestScraper::scrape('https://example.com')
            ->submitAndCapture('form', ['expect' => 'application/pdf'])
            ->getActions();

        $this->assertSame(
            [['type' => 'submitAndCapture', 'formSelector' => 'form', 'expect' => 'application/pdf']],
            $actions
        );
    }
}
