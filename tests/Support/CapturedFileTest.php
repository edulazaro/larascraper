<?php

namespace EduLazaro\Larascraper\Tests\Support;

use EduLazaro\Larascraper\Support\CapturedFile;
use EduLazaro\Larascraper\Tests\BaseTestCase;

class CapturedFileTest extends BaseTestCase
{
    public function test_bytes_size_and_content_type(): void
    {
        $file = new CapturedFile('hello', 'application/pdf');

        $this->assertSame('hello', $file->bytes());
        $this->assertSame(5, $file->size());
        $this->assertSame('application/pdf', $file->contentType());
    }

    public function test_save_writes_the_bytes_and_returns_the_path(): void
    {
        $file = new CapturedFile('data');
        $path = tempnam(sys_get_temp_dir(), 'lscrtest') . '.bin';

        $this->assertSame($path, $file->save($path));
        $this->assertSame('data', file_get_contents($path));

        @unlink($path);
    }

    public function test_text_and_vision_return_empty_on_empty_bytes(): void
    {
        $file = new CapturedFile('');

        $this->assertSame('', $file->text());
        $this->assertSame('', $file->vision());
    }

    public function test_unknown_text_engine_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown text engine');

        (new CapturedFile('%PDF-1.4 fake'))->text('nope');
    }

    public function test_strip_code_fences_removes_fence_only_lines(): void
    {
        $file = new ExposedCapturedFile('');

        $this->assertSame('keep me', $file->call('stripCodeFences', "```json\nkeep me\n```"));
    }

    public function test_fix_mojibake_repairs_double_encoded_utf8(): void
    {
        $file = new ExposedCapturedFile('');

        $good = 'número de expedición';
        $mojibake = mb_convert_encoding($good, 'UTF-8', 'ISO-8859-1');   // double-encode

        $this->assertNotSame($good, $mojibake);                          // genuinely broken
        $this->assertSame($good, $file->call('fixMojibake', $mojibake)); // repaired
        $this->assertSame($good, $file->call('fixMojibake', $good));     // idempotent on clean text
    }

    public function test_normalize_text_collapses_whitespace_and_blank_lines(): void
    {
        $file = new ExposedCapturedFile('');

        $this->assertSame("a b\n\nc", $file->call('normalizeText', "  a   b\n\n\n\nc  "));
    }
}

/**
 * Test-only subclass exposing CapturedFile's protected, dependency-free text
 * post-processors so they can be unit-tested without any PDF engine.
 */
class ExposedCapturedFile extends CapturedFile
{
    public function call(string $method, mixed ...$args): mixed
    {
        return $this->{$method}(...$args);
    }
}
