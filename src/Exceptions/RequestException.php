<?php

namespace EduLazaro\Larascraper\Exceptions;

use EduLazaro\Larascraper\Support\RequestResponse;
use RuntimeException;
use Throwable;

/**
 * A request-level failure: the network was down, or an HTTP status the fetcher
 * treats as failure survived the bounded retry loop.
 *
 * It is thrown by FetchBuilder::fetch() and carries the RequestResponse it was
 * built from, so a caller can branch on `$e->response->status` (or
 * `$e->response()`), inspect the returned html/cookies, or re-raise.
 */
class RequestException extends RuntimeException
{
    /**
     * @param RequestResponse $response The HTTP-layer response that failed.
     * @param string $message A custom message; defaults to the response error or status.
     * @param int $code The exception code.
     * @param Throwable|null $previous The previous throwable used for chaining.
     */
    public function __construct(
        public RequestResponse $response,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        if ($message === '') {
            $message = $response->error ?: "Request failed with status {$response->status}";
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * The HTTP-layer response that triggered this failure.
     */
    public function response(): RequestResponse
    {
        return $this->response;
    }
}
