<?php

namespace EduLazaro\Larascraper\Support;

/**
 * Value object holding the result of a scrape.
 */
class ScraperResponse
{
    /** @var bool Whether the scrape succeeded. */
    public bool $success = false;

    /** @var int HTTP status code returned by the page. */
    public int $status = 0;

    /** @var string|null Error message when the scrape failed, null otherwise. */
    public ?string $error = null;

    /** @var string The fetched HTML (empty on failure). */
    public string $html = '';

    /**
     * @param bool $success Whether the scrape succeeded.
     * @param int $status HTTP status code.
     * @param string|null $error Error message, if any.
     * @param string $html The fetched HTML.
     */
    public function __construct(bool $success = false, int $status = 0, string|null $error = null, string $html = '')
    {
        $this->success = $success ;
        $this->status = $status ;
        $this->error = $error ;
        $this->html = $html ;
    }
}
