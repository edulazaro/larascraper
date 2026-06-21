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

    /** @var string|null The raw bytes of a captured file/binary (e.g. a PDF), null otherwise. */
    public ?string $file = null;

    /** @var string|null The content type of the captured file (e.g. 'application/pdf'). */
    public ?string $contentType = null;

    /**
     * A response carries either `data` (parsed HTML) or `file`/`contentType`
     * (a captured binary), depending on the scrape.
     *
     * @param bool $success Whether the scrape succeeded.
     * @param int $status HTTP status code.
     * @param string|null $error Error message, if any.
     * @param string $html The fetched HTML.
     * @param mixed $data The parsed data from handle().
     * @param string|null $file Raw bytes of a captured file/binary.
     * @param string|null $contentType Content type of the captured file.
     */
    public function __construct(
        bool $success = false,
        int $status = 0,
        string|null $error = null,
        string $html = '',
        mixed $data = null,
        ?string $file = null,
        ?string $contentType = null,
    ) {
        $this->success = $success ;
        $this->status = $status ;
        $this->error = $error ;
        $this->html = $html ;
        $this->data = $data ;
        $this->file = $file ;
        $this->contentType = $contentType ;
    }
}
