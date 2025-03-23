<?php

namespace EduLazaro\Larascraper\Support;

class ScraperResponse
{

    public bool $success = false;
    public int $status = 0;
    public ?string $error = null;
    public string $html = '';

    public function __construct(bool $success = false, int $status = 0, string|null $error = null, string $html = '')
    {
        $this->success = $success ;
        $this->status = $status ;
        $this->error = $error ;
        $this->html = $html ;
    }
}
