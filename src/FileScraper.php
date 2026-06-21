<?php

namespace EduLazaro\Larascraper;

/**
 * A ready-to-use scraper for downloading a file/binary (e.g. a PDF) instead of
 * parsing HTML. Use it directly, no subclass needed:
 *
 *     $result = FileScraper::scrape($url)
 *         ->submitAndCapture('form', ['expect' => 'application/pdf'])
 *         ->run();
 *
 *     file_put_contents('doc.pdf', $result->file);
 *
 * The captured bytes land in $result->file and the type in $result->contentType.
 * There is nothing to parse, so handle() is defined here (returning null) and you
 * never have to write it.
 */
class FileScraper extends Scraper
{
    /**
     * No HTML parsing: the result is the captured file, in $result->file.
     *
     * @return null
     */
    protected function handle(): mixed
    {
        return null;
    }
}
