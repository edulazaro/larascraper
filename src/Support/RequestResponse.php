<?php

namespace EduLazaro\Larascraper\Support;

/**
 * The HTTP-layer value object: the raw result of a single fetch, produced by
 * FetchBuilder::fetch() from a runner's normalized array.
 *
 * It is the low-level counterpart of ScraperResponse. Where ScraperResponse
 * carries the parsed, scraper-level result, RequestResponse carries the wire
 * facts (status, html, cookies, an optional captured binary). It is reachable
 * inside a scraper as `$this->request`, and is carried by RequestException so a
 * caller can branch on `->response->status` after a request-level failure. It is
 * NOT surfaced on ScraperResponse, which stays about content only.
 */
class RequestResponse
{
    /**
     * @param int $status The HTTP status code (0 when the transport never got a response).
     * @param string|null $error The transport/error message when the request failed, null otherwise.
     * @param string $html The fetched HTML (empty on failure or for a binary capture).
     * @param CapturedFile|null $file A captured file (a PDF, etc.), or null. Read it with ->text()/->vision()/->bytes().
     * @param string|null $contentType The content type of the response (e.g. 'application/pdf'), or null.
     * @param array<string, string> $cookies Response cookies as a name => value map.
     * @param array<string, list<string>> $diagnostics What the page did that a DOM
     *        cannot show: 'xhr' (the calls it made and their status), 'failed'
     *        (requests that never completed) and 'errors' (exceptions its own
     *        JavaScript raised). Empty when nothing went wrong. A page whose DOM
     *        never changed looks the same whether its XHR was never sent, was
     *        refused, or threw — this is what tells those apart.
     */
    public function __construct(
        public int $status = 0,
        public ?string $error = null,
        public string $html = '',
        public ?CapturedFile $file = null,
        public ?string $contentType = null,
        public array $cookies = [],
        public array $diagnostics = [],
    ) {
    }

    /**
     * Whether the response carries a 2xx status code.
     *
     * A convenience for callers; the retry/throw decision inside fetch() is
     * driven by the runner's own success flag, not by this method.
     */
    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
