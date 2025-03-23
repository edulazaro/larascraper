<?php

namespace EduLazaro\Larascraper\Tests\Support;

use EduLazaro\Larascraper\Scraper;

class TestScraper extends Scraper
{
    public function handle(): array
    {
        return [
            'title' => $this->crawler->filter('title')->text('')
        ];
    }
}
