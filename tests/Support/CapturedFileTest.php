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

        (new CapturedFile('%PDF-1.4 fake'))->text('nope');
    }
}
