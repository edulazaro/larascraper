<?php

namespace EduLazaro\Larascraper\Tests\Support;

use EduLazaro\Larascraper\Scraper;
use Symfony\Component\DomCrawler\Crawler;

class TestScraper extends Scraper
{
    protected Crawler $crawler;

    public function handle(Crawler $crawler): array
    {
        return [
            'title' => $crawler->filter('title')->text('')
        ];
    }
}
