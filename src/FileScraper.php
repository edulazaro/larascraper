<?php

namespace EduLazaro\Larascraper;

use EduLazaro\Larascraper\Support\ScraperResponse;

/**
 * Deprecated convenience subclass kept for source compatibility with the 2.x
 * line. In v3 there is no dedicated file scraper: capturing a binary is done
 * inline from any scraper via the fetch chain, for example a PDF datasheet:
 *
 *     $file = $this->scrape($url)->capture()->file();
 *     file_put_contents('doc.pdf', $file->bytes());
 *
 * This class simply fetches the URL and hands back a {@see ScraperResponse}. The
 * captured binary, when the underlying request produced one, is reachable at
 * $response->request->file (a {@see \EduLazaro\Larascraper\Support\CapturedFile}).
 *
 * @deprecated 3.0 use $this->scrape($url)->capture()->file()
 */
class FileScraper extends Scraper
{
    /**
     * Fetch the URL and expose the binary via $response->request->file.
     *
     * @param  string  $url  The URL to fetch.
     * @return \EduLazaro\Larascraper\Support\ScraperResponse
     *
     * @deprecated 3.0 use $this->scrape($url)->capture()->file()
     */
    protected function handle(string $url): ScraperResponse
    {
        return $this->scrape($url)->run();
    }
}
