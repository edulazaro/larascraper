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

    /** @var mixed The parsed data returned by the scraper's handle() method (HTML scrapes). */
    public mixed $data = null;

    /** @var CapturedFile|null A captured file (e.g. a PDF), or null. Read it with ->text()/->vision()/->bytes(). */
    public ?CapturedFile $file = null;

    /** @var string|null The content type of the captured file (e.g. 'application/pdf'). */
    public ?string $contentType = null;

    /** @var array<string, string> Response cookies as a name => value map. */
    public array $cookies = [];

    /**
     * A response carries either `data` (parsed HTML) or `file`/`contentType`
     * (a captured binary), depending on the scrape.
     *
     * @param bool $success Whether the scrape succeeded.
     * @param int $status HTTP status code.
     * @param string|null $error Error message, if any.
     * @param string $html The fetched HTML.
     * @param mixed $data The parsed data from handle().
     * @param CapturedFile|null $file A captured file (a PDF, etc.).
     * @param string|null $contentType Content type of the captured file.
     * @param array<string, string> $cookies Response cookies (name => value).
     */
    public function __construct(
        bool $success = false,
        int $status = 0,
        string|null $error = null,
        string $html = '',
        mixed $data = null,
        ?CapturedFile $file = null,
        ?string $contentType = null,
        array $cookies = [],
    ) {
        $this->success = $success ;
        $this->status = $status ;
        $this->error = $error ;
        $this->html = $html ;
        $this->data = $data ;
        $this->file = $file ;
        $this->contentType = $contentType ;
        $this->cookies = $cookies ;
    }
}
