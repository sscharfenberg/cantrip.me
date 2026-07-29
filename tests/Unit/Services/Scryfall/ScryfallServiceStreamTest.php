<?php

namespace Tests\Unit\Services\Scryfall;

use App\Services\Scryfall\ScryfallService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exposes the protected streaming reader for testing.
 */
class StreamProbe extends ScryfallService
{
    /**
     * @param  callable(array<string, mixed>): void  $onRow
     */
    public function read(string $uri, callable $onRow): int
    {
        return $this->streamJsonl($uri, $onRow);
    }
}

/**
 * Coverage for {@see ScryfallService::streamJsonl()}, the reader every
 * Scryfall bulk import runs through since Scryfall switched to gzipped
 * JSON Lines.
 *
 * The truncation cases matter more than the happy path. The old
 * `JsonParser` traversal failed loudly on a short read because an
 * unterminated JSON array is a parse error; a line-oriented reader has to
 * detect that itself, or a connection dropped mid-transfer would import a
 * partial dataset and report success. No DB and no network — fixtures on
 * local disk exercise the same code path the CDN does.
 */
class ScryfallServiceStreamTest extends TestCase
{
    /** @var array<int, string> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->fixtures = [];

        parent::tearDown();
    }

    /**
     * Write raw bytes to a tracked tempfile with the given suffix (the
     * `.gz` suffix is what selects the reader's inflate path).
     */
    private function fixture(string $contents, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jsonl_test_');
        if ($path === false) {
            $this->fail('failed to create tempfile for fixture');
        }
        unlink($path);
        $path .= $suffix;
        file_put_contents($path, $contents);
        $this->fixtures[] = $path;

        return $path;
    }

    private function sampleJsonl(int $rows = 200): string
    {
        $jsonl = '';
        for ($i = 0; $i < $rows; $i++) {
            $jsonl .= json_encode(['object' => 'card', 'n' => $i, 'pad' => str_repeat('x', 120)])."\n";
        }

        return $jsonl;
    }

    /**
     * @return array{0: int, 1: array<int, array<string, mixed>>}
     */
    private function readAll(string $path): array
    {
        $rows = [];
        $count = (new StreamProbe)->read($path, function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        return [$count, $rows];
    }

    #[Test]
    public function it_streams_every_row_of_a_gzipped_jsonl_bulk(): void
    {
        $path = $this->fixture(gzencode($this->sampleJsonl(200)), '.jsonl.gz');

        [$count, $rows] = $this->readAll($path);

        $this->assertSame(200, $count);
        $this->assertCount(200, $rows);
        $this->assertSame(0, $rows[0]['n']);
        $this->assertSame(199, $rows[199]['n']);
        $this->assertSame('card', $rows[0]['object']);
    }

    #[Test]
    public function it_reads_a_final_line_that_has_no_trailing_newline(): void
    {
        $path = $this->fixture(gzencode(rtrim($this->sampleJsonl(50), "\n")), '.jsonl.gz');

        [$count] = $this->readAll($path);

        $this->assertSame(50, $count);
    }

    #[Test]
    public function it_skips_blank_lines(): void
    {
        $jsonl = json_encode(['n' => 1])."\n\n".json_encode(['n' => 2])."\n\n";
        $path = $this->fixture(gzencode($jsonl), '.jsonl.gz');

        [$count, $rows] = $this->readAll($path);

        $this->assertSame(2, $count);
        $this->assertSame([1, 2], array_column($rows, 'n'));
    }

    #[Test]
    public function it_aborts_on_a_truncated_gzip_stream(): void
    {
        $gz = gzencode($this->sampleJsonl(200));
        $path = $this->fixture(substr($gz, 0, (int) (strlen($gz) * 0.6)), '.jsonl.gz');

        $this->expectException(\RuntimeException::class);

        $this->readAll($path);
    }

    /**
     * The case a line-oriented reader cannot see on its own: every byte of
     * the inflated payload arrived and the last line ended on a newline, so
     * the stream looks complete. Only gzip's CRC/length trailer proves it
     * wasn't — which is why the reader inflates by hand rather than through
     * the `compress.zlib://` wrapper.
     */
    #[Test]
    public function it_aborts_when_the_gzip_checksum_trailer_is_missing(): void
    {
        $path = $this->fixture(substr(gzencode($this->sampleJsonl(200)), 0, -8), '.jsonl.gz');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ended early or failed its checksum/');

        $this->readAll($path);
    }

    #[Test]
    public function it_aborts_on_a_malformed_line_rather_than_skipping_it(): void
    {
        $jsonl = json_encode(['n' => 1])."\n".'{"n": 2, broken'."\n";
        $path = $this->fixture(gzencode($jsonl), '.jsonl.gz');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/malformed JSON on line 2/');

        $this->readAll($path);
    }

    #[Test]
    public function it_reads_uncompressed_sources_for_local_fixtures(): void
    {
        $path = $this->fixture($this->sampleJsonl(30), '.jsonl');

        [$count] = $this->readAll($path);

        $this->assertSame(30, $count);
    }

    #[Test]
    public function it_fails_when_the_source_cannot_be_opened(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed to open bulk stream/');

        $this->readAll(sys_get_temp_dir().'/definitely-not-here-'.getmypid().'.jsonl.gz');
    }
}
