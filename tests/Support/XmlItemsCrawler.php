<?php

namespace EduLazaro\Larascraper\Tests\Support;

use EduLazaro\Larascraper\Crawler;

/**
 * A crawler that reads an XML document via filter($sel, 'xml').
 *
 * It proves the XML backing: the input is XML (not HTML), and <item> nodes are
 * selected with a CSS selector that compiles to XPath, returning each item's
 * <title> text.
 */
class XmlItemsCrawler extends Crawler
{
    /**
     * @return array<int, string> The titles of every <item>.
     */
    protected function handle(): array
    {
        return $this->filter('item', 'xml')
            ->each(fn ($node) => $node->filter('title')->text(''));
    }
}
