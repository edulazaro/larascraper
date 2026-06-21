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
        ]], $actions);
    }

    public function test_goto_attr_defaults_to_href(): void
    {
        $actions = TestScraper::scrape('https://example.com')
            ->gotoAttr('a.next')
            ->getActions();

        $this->assertSame('href', $actions[0]['attr']);
    }
}
