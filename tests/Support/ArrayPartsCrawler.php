<?php

namespace EduLazaro\Larascraper\Tests\Support;

use EduLazaro\Larascraper\Crawler;

/**
 * A crawler constructed with a multi-part ARRAY input rather than a string.
 *
 * It proves that raw() hands back the whole array untouched, so a subclass can
 * read named parts without any HTML DomCrawler being built.
 */
class ArrayPartsCrawler extends Crawler
{
    /**
     * @return array{a: mixed, b: mixed}
     */
    protected function handle(): array
    {
        $parts = $this->raw();

        return [
            'a' => $parts['a'],
            'b' => $parts['b'],
        ];
    }
}
