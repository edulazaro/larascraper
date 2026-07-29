<?php

namespace EduLazaro\Larascraper\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A file captured during a scrape (a PDF, etc.), exposed as `$this->file` inside
 * a scraper's handle(). Turn it into text with:
 *
 *   $this->file->text()          // the PDF's text layer (fast, free)
 *   $this->file->vision('ai')    // OCR, for scanned pages
 *
 * or handle the bytes with bytes() / save(). Extraction runs in PHP: it shells
 * out to system tools (ghostscript, poppler, tesseract) and/or a vision model;
 * each engine fails with a clear message if its tool is missing.
 */
class CapturedFile
{
    public function __construct(
        protected string $bytes,
        protected ?string $contentType = null,
    ) {
    }

    /** The raw bytes of the file. */
    public function bytes(): string
    {
        return $this->bytes;
    }

    /** The content type, e.g. 'application/pdf', or null. */
    public function contentType(): ?string
    {
        return $this->contentType;
    }

    /** Size in bytes. */
    public function size(): int
    {
        return strlen($this->bytes);
    }

    /** Write the bytes to disk. Returns the path. */
    public function save(string $path): string
    {
        file_put_contents($path, $this->bytes);

        return $path;
    }

    /**
     * Extract the TEXT LAYER of the PDF. No OCR: reads the text the PDF already
     * contains. Fast and free. Returns '' for a scanned PDF (no text layer), so
     * you can fall back to vision().
     *
     * @param string $engine 'gs' (ghostscript, default), 'poppler' (pdftotext)
     *        or 'smalot' (smalot/pdfparser, pure PHP).
     */
    public function text(string $engine = 'gs'): string
    {
        if ($this->bytes === '') {
            return '';
        }

        return $this->withTempPdf(fn (string $pdf) => match ($engine) {
            'gs' => $this->textWithGhostscript($pdf),
            'poppler' => $this->textWithPoppler($pdf),
            'smalot' => $this->textWithSmalot($pdf),
            default => throw new RuntimeException("Unknown text engine [{$engine}]. Use gs, poppler or smalot."),
        });
    }

    /**
     * OCR the PDF: rasterize each page to an image and read it. For scanned PDFs
     * that have no text layer. Slower, and the 'ai' engine costs money.
     *
     * @param string $engine 'ai' (vision model, default) or 'tesseract'.
     */
    public function vision(string $engine = 'ai'): string
    {
        if ($this->bytes === '') {
            return '';
        }

        return $this->withTempPdf(function (string $pdf) use ($engine) {
            $images = $this->rasterize($pdf);

            $pages = [];
            foreach ($images as $image) {
                try {
                    $pages[] = match ($engine) {
                        'ai' => $this->ocrWithAi($image),
                        'tesseract' => $this->ocrWithTesseract($image),
                        default => throw new RuntimeException("Unknown vision engine [{$engine}]. Use ai or tesseract."),
                    };
                } finally {
                    @unlink($image);
                }
            }

            return trim(implode("\n\n", array_filter($pages, fn ($p) => trim((string) $p) !== '')));
        });
    }

    // ------------------------------------------------------------------
    // Text-layer engines
    // ------------------------------------------------------------------

    protected function textWithGhostscript(string $pdf): string
    {
        $this->requireBinary('gs', 'ghostscript');

        $cmd = sprintf(
            'gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=txtwrite -sOutputFile=- %s 2>/dev/null',
            escapeshellarg($pdf),
        );

        return $this->normalizeText((string) shell_exec($cmd));
    }

    protected function textWithPoppler(string $pdf): string
    {
        $this->requireBinary('pdftotext', 'poppler-utils');

        $cmd = sprintf('pdftotext -layout %s - 2>/dev/null', escapeshellarg($pdf));

        return $this->normalizeText((string) shell_exec($cmd));
    }

    protected function textWithSmalot(string $pdf): string
    {
        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new RuntimeException(
                'The smalot text engine needs smalot/pdfparser. Install it with: composer require smalot/pdfparser'
            );
        }

        $text = (new \Smalot\PdfParser\Parser())->parseFile($pdf)->getText();

        return $this->normalizeText($text);
    }

    // ------------------------------------------------------------------
    // Vision (OCR) engines
    // ------------------------------------------------------------------

    /**
     * Rasterize every page of the PDF to a PNG with pdftoppm. Returns the sorted
     * list of image paths (caller deletes them).
     *
     * @return list<string>
     */
    protected function rasterize(string $pdf): array
    {
        $this->requireBinary('pdftoppm', 'poppler-utils');

        $dpi = (int) $this->extractConfig('vision_dpi', 150);
        $prefix = tempnam(sys_get_temp_dir(), 'lscrimg_');
        @unlink($prefix); // pdftoppm appends -N.png to the prefix

        $cmd = sprintf(
            'pdftoppm -r %d -png %s %s 2>/dev/null',
            $dpi,
            escapeshellarg($pdf),
            escapeshellarg($prefix),
        );
        shell_exec($cmd);

        $images = glob($prefix . '*.png') ?: [];
        sort($images); // page-1, page-2, ... in order

        return $images;
    }

    protected function ocrWithTesseract(string $image): string
    {
        $this->requireBinary('tesseract', 'tesseract-ocr (with language data)');

        $lang = (string) $this->extractConfig('vision_lang', 'spa');

        $cmd = sprintf(
            'tesseract %s stdout -l %s 2>/dev/null',
            escapeshellarg($image),
            escapeshellarg($lang),
        );

        return $this->normalizeText((string) shell_exec($cmd));
    }

    /**
     * OCR one page image with a vision model (OpenAI-compatible chat/completions
     * with an image_url).
     */
    protected function ocrWithAi(string $image): string
    {
        $key = $this->visionApiKey();
        if (! $key) {
            throw new RuntimeException(
                'vision("ai") needs an OpenAI API key. Set config larascraper.openai_key, services.openai.key or OPENAI_API_KEY.'
            );
        }

        $base64 = base64_encode((string) file_get_contents($image));
        $model = (string) $this->extractConfig('vision_model', 'gpt-4o-mini');

        $response = Http::withToken($key)
            ->timeout(120)
            ->post(rtrim((string) $this->extractConfig('vision_base_url', 'https://api.openai.com/v1'), '/') . '/chat/completions', [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Extract the literal text from this image, preserving the structure, order and hierarchy (headings, subheadings, lists). Do NOT translate, comment or interpret. Output only the text present in the image. If the image has no readable text, return an empty string.',
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $base64]],
                        ],
                    ],
                ],
                'max_completion_tokens' => (int) $this->extractConfig('vision_max_tokens', 2000),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('vision("ai") request failed: HTTP ' . $response->status() . ' ' . $response->body());
        }

        return $this->normalizeText((string) $response->json('choices.0.message.content'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Write the bytes to a temp PDF, run $fn($path), always clean up. */
    protected function withTempPdf(callable $fn): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lscr_') . '.pdf';
        file_put_contents($tmp, $this->bytes);

        try {
            return (string) $fn($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    protected function requireBinary(string $binary, string $package): void
    {
        if (trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null')) === '') {
            throw new RuntimeException("The [{$binary}] binary is not installed (from {$package}). Install it to use this engine.");
        }
    }

    protected function visionApiKey(): ?string
    {
        return $this->extractConfig('openai_key')
            ?? (function_exists('config') ? config('services.openai.key') : null)
            ?? (function_exists('env') ? env('OPENAI_API_KEY') : null);
    }

    /** Read a larascraper.* config value with a fallback default. */
    protected function extractConfig(string $key, mixed $default = null): mixed
    {
        return function_exists('config') ? config("larascraper.{$key}", $default) : $default;
    }

    /**
     * Collapse whitespace and repair the classic smalot/pdfparser double-encoded
     * UTF-8 mojibake ("nÃºmero" for "número").
     */
    protected function normalizeText(string $text): string
    {
        $text = $this->fixMojibake($text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    protected function fixMojibake(string $text): string
    {
        $markers = ['Ã¡', 'Ã©', 'Ã­', 'Ã³', 'Ãº', 'Ã±', 'Ã‘', 'Â¡', 'Â¿', 'Â°', 'Ã¼', 'Ãœ', 'Ã§', 'Ã‡'];

        $hasMojibake = false;
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                $hasMojibake = true;
                break;
            }
        }

        if (! $hasMojibake) {
            return $text;
        }

        $fixed = @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
        if ($fixed === false || $fixed === '') {
            return $text;
        }

        $before = 0;
        $after = 0;
        foreach ($markers as $marker) {
            $before += substr_count($text, $marker);
            $after += substr_count($fixed, $marker);
        }

        return $after < $before ? $fixed : $text;
    }
}
