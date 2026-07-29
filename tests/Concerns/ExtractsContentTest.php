<?php

namespace EduLazaro\Larascraper\Tests\Concerns;

use EduLazaro\Larascraper\Tests\BaseTestCase;
use EduLazaro\Larascraper\Tests\Support\TestScraper;

class ExtractsContentTest extends BaseTestCase
{
    public function test_text_returns_empty_when_nothing_was_captured(): void
    {
        $this->assertSame('', TestScraper::scrape('https://example.com')->text());
    }

    public function test_vision_returns_empty_when_nothing_was_captured(): void
    {
        $this->assertSame('', TestScraper::scrape('https://example.com')->vision());
    }
}
