<?php

namespace Tests\Unit\Services\Scryfall;

use App\Services\Scryfall\ScryfallRunStats;
use App\Services\Scryfall\ScryfallService;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
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
 * Stands in for Scryfall's CDN: each call to the network seam is answered
 * by the next scripted response, and every request is recorded so a test
 * can check what a resume asked for.
 */
class FakeCdnProbe extends StreamProbe
{
    /** @var list<array{offset: int, validator: string|null}> */
    public array $requests = [];

    /**
     * @param  list<Closure(int, ?string): (array{0: resource, 1: list<string>}|null)>  $responses  Null stands for a connection that could not be opened.
     */
    public function __construct(private array $responses) {}

    protected function openBulkSource(string $uri, int $offset = 0, ?string $validator = null): ?array
    {
        $this->requests[] = ['offset' => $offset, 'validator' => $validator];
        $respond = $this->responses[count($this->requests) - 1]
            ?? throw new \LogicException('the reader asked the fake CDN more often than the test scripted.');

        return $respond($offset, $validator);
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

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        ScryfallRunStats::reset();
        Log::shouldReceive('channel')->with('scryfall')->andReturnSelf();
        Log::shouldReceive('warning');
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
        $this->expectExceptionMessageMatches('/ended early after \d+ rows .*, although all \d+ bytes arrived: the file is cut short/');

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
        $this->expectExceptionMessageMatches('/ended early/');

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

    private const ETAG = '"d9bf157ccd88776ae8e8a859e1aa54a1"';

    private const LAST_MODIFIED = 'Thu, 12 Nov 2026 22:06:29 GMT';

    /**
     * A response the way {@see ScryfallService::openBulkSource()} returns it:
     * the body in memory, the header lines as PHP's HTTP wrapper records them.
     *
     * @param  list<string>  $headers
     * @return array{0: resource, 1: list<string>}
     */
    private static function respond(string $body, array $headers): array
    {
        $handle = fopen('php://memory', 'w+b');
        fwrite($handle, $body);
        rewind($handle);

        return [$handle, $headers];
    }

    /**
     * The first response: 200 with the whole file — or the connection
     * dropping once `$until` bytes of it went out.
     *
     * @param  list<string>|null  $headers  Defaults to Content-Length, a strong ETag and Last-Modified, as the CDN sends.
     */
    private function serveFile(string $gz, ?int $until = null, ?array $headers = null): Closure
    {
        $headers ??= ['HTTP/1.1 200 OK', 'Content-Length: '.strlen($gz), 'ETag: '.self::ETAG, 'Last-Modified: '.self::LAST_MODIFIED];

        return fn (): array => self::respond(substr($gz, 0, $until ?? strlen($gz)), $headers);
    }

    /**
     * A resume answered the way the CDN does: 206 from the offset asked
     * for, the connection dropping again once byte `$until` of the file
     * went out. `$skew` makes it start somewhere else, `$total` names
     * another file size than the real one.
     */
    private function serveRest(string $gz, ?int $until = null, int $skew = 0, ?int $total = null): Closure
    {
        return function (int $offset) use ($gz, $until, $skew, $total): array {
            $from = $offset + $skew;
            $size = strlen($gz);

            return self::respond(substr($gz, $from, max(0, ($until ?? $size) - $from)), [
                'HTTP/1.1 206 Partial Content',
                'Content-Length: '.($size - $from),
                'ETag: '.self::ETAG,
                'content-range: bytes '.$from.'-'.($size - 1).'/'.($total ?? $size),
            ]);
        };
    }

    private function serveStatus(int $status): Closure
    {
        return fn (): array => self::respond('<html>error</html>', ['HTTP/1.1 '.$status.' Whatever']);
    }

    private function unreachable(): Closure
    {
        return fn (): ?array => null;
    }

    /**
     * Read through the fake CDN, keeping what reached the callback even when
     * the read throws.
     *
     * @return array{0: int|null, 1: list<array<string, mixed>>, 2: \RuntimeException|null}
     */
    private function readThrough(FakeCdnProbe $cdn): array
    {
        $rows = [];
        try {
            $count = $cdn->read('https://data.scryfall.io/default-cards/default-cards.jsonl.gz', function (array $row) use (&$rows): void {
                $rows[] = $row;
            });

            return [$count, $rows, null];
        } catch (\RuntimeException $e) {
            return [null, $rows, $e];
        }
    }

    /**
     * @param  list<int>  $milliseconds
     */
    private static function assertSlept(array $milliseconds): void
    {
        Sleep::assertSequence(array_map(fn (int $ms): Sleep => Sleep::for($ms)->milliseconds(), $milliseconds));
    }

    #[Test]
    public function a_download_that_never_drops_is_read_on_one_connection(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cdn = new FakeCdnProbe([$this->serveFile($gz)]);

        [$count, , $error] = $this->readThrough($cdn);

        $this->assertNull($error);
        $this->assertSame(2000, $count);
        $this->assertCount(1, $cdn->requests);
        Sleep::assertNeverSlept();
        $this->assertSame(0, ScryfallRunStats::$bulkReconnects);
    }

    #[Test]
    public function it_resumes_a_download_that_drops_mid_stream_at_once(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cut = intdiv(strlen($gz) * 2, 5);
        $cdn = new FakeCdnProbe([$this->serveFile($gz, until: $cut), $this->serveRest($gz)]);

        [$count, $rows, $error] = $this->readThrough($cdn);

        $this->assertNull($error);
        $this->assertSame(2000, $count);
        $this->assertSame(range(0, 1999), array_column($rows, 'n'), 'every row exactly once, in order');
        $this->assertSame([
            ['offset' => 0, 'validator' => null],
            ['offset' => $cut, 'validator' => self::ETAG],
        ], $cdn->requests);
        Sleep::assertNeverSlept();
        $this->assertSame(1, ScryfallRunStats::$bulkReconnects);
    }

    #[Test]
    public function it_resumes_again_when_the_resumed_connection_drops_too(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $first = intdiv(strlen($gz), 4);
        $second = intdiv(strlen($gz) * 3, 4);
        $cdn = new FakeCdnProbe([
            $this->serveFile($gz, until: $first),
            $this->serveRest($gz, until: $second),
            $this->serveRest($gz),
        ]);

        [$count, $rows, $error] = $this->readThrough($cdn);

        $this->assertNull($error);
        $this->assertSame(range(0, 1999), array_column($rows, 'n'));
        $this->assertSame(2000, $count);
        $this->assertSame([0, $first, $second], array_column($cdn->requests, 'offset'));
        $this->assertSame(2, ScryfallRunStats::$bulkReconnects);
    }

    #[Test]
    public function a_reconnect_that_fails_is_tried_again_after_a_wait(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cut = intdiv(strlen($gz), 2);
        $cdn = new FakeCdnProbe([$this->serveFile($gz, until: $cut), $this->unreachable(), $this->serveRest($gz)]);

        [$count, $rows, $error] = $this->readThrough($cdn);

        $this->assertNull($error);
        $this->assertSame(range(0, 1999), array_column($rows, 'n'));
        $this->assertSame(2000, $count);
        $this->assertSame([0, $cut, $cut], array_column($cdn->requests, 'offset'));
        self::assertSlept([5_000]);
    }

    #[Test]
    public function a_5xx_on_resume_is_tried_again(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cut = intdiv(strlen($gz), 2);
        $cdn = new FakeCdnProbe([$this->serveFile($gz, until: $cut), $this->serveStatus(503), $this->serveRest($gz)]);

        [$count, , $error] = $this->readThrough($cdn);

        $this->assertNull($error);
        $this->assertSame(2000, $count);
        self::assertSlept([5_000]);
    }

    /**
     * Separate drops far apart in one long download each get the whole
     * budget: data arriving in between starts the count over, so the second
     * stall waits 5 s again rather than 15 s.
     */
    #[Test]
    public function data_arriving_starts_the_attempt_count_over(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $first = intdiv(strlen($gz), 4);
        $second = intdiv(strlen($gz) * 3, 4);
        $cdn = new FakeCdnProbe([
            $this->serveFile($gz, until: $first),
            $this->unreachable(),
            $this->serveRest($gz, until: $second),
            $this->unreachable(),
            $this->serveRest($gz),
        ]);

        [$count, , $error] = $this->readThrough($cdn);

        $this->assertNull($error);
        $this->assertSame(2000, $count);
        self::assertSlept([5_000, 5_000]);
    }

    #[Test]
    public function it_gives_up_after_five_attempts_in_a_row_that_brought_nothing(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cut = intdiv(strlen($gz), 2);
        $cdn = new FakeCdnProbe([
            $this->serveFile($gz, until: $cut),
            ...array_fill(0, 5, $this->unreachable()),
        ]);

        [, , $error] = $this->readThrough($cdn);

        $this->assertNotNull($error);
        $this->assertMatchesRegularExpression('/ended early after \d+ rows .* could not be resumed: could not connect, 5 attempts in a row/', $error->getMessage());
        $this->assertCount(6, $cdn->requests);
        self::assertSlept([5_000, 15_000, 30_000, 60_000]);
    }

    /**
     * Each resume connects fine and then ends before a single byte. The
     * attempts must still run out instead of looping forever.
     */
    #[Test]
    public function it_gives_up_when_every_resumed_connection_ends_before_any_data(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cut = intdiv(strlen($gz), 2);
        $cdn = new FakeCdnProbe([
            $this->serveFile($gz, until: $cut),
            ...array_fill(0, 5, $this->serveRest($gz, until: $cut)),
        ]);

        [, , $error] = $this->readThrough($cdn);

        $this->assertNotNull($error);
        $this->assertMatchesRegularExpression('/could not be resumed: the connection ended before any data, 5 attempts in a row/', $error->getMessage());
        $this->assertCount(6, $cdn->requests);
    }

    #[Test]
    public function a_first_open_that_fails_is_tried_again(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cdn = new FakeCdnProbe([$this->unreachable(), $this->serveStatus(503), $this->serveFile($gz)]);

        [$count, , $error] = $this->readThrough($cdn);

        $this->assertNull($error);
        $this->assertSame(2000, $count);
        self::assertSlept([5_000, 15_000]);
        $this->assertSame(1, ScryfallRunStats::$bulkReconnects);
    }

    #[Test]
    public function a_first_open_that_never_succeeds_fails_to_open(): void
    {
        $cdn = new FakeCdnProbe(array_fill(0, 5, $this->serveStatus(503)));

        [, , $error] = $this->readThrough($cdn);

        $this->assertNotNull($error);
        $this->assertMatchesRegularExpression('/failed to open bulk stream .*: HTTP 503, 5 attempts in a row/', $error->getMessage());
    }

    #[Test]
    public function a_4xx_on_the_first_open_is_an_answer_not_a_hiccup(): void
    {
        $cdn = new FakeCdnProbe([$this->serveStatus(404)]);

        [, , $error] = $this->readThrough($cdn);

        $this->assertNotNull($error);
        $this->assertMatchesRegularExpression('/failed to open bulk stream .*: HTTP 404/', $error->getMessage());
        $this->assertCount(1, $cdn->requests);
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function a_4xx_on_resume_aborts_at_once(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cdn = new FakeCdnProbe([$this->serveFile($gz, until: intdiv(strlen($gz), 2)), $this->serveStatus(416)]);

        [, , $error] = $this->readThrough($cdn);

        $this->assertNotNull($error);
        $this->assertMatchesRegularExpression('/asked for the rest, Scryfall answered HTTP 416/', $error->getMessage());
        $this->assertCount(2, $cdn->requests);
        Sleep::assertNeverSlept();
    }

    /**
     * Headers arrived, then the connection ended before the first body
     * byte. Nothing was read, so starting over is safe — and must not send
     * `If-Range` without `Range`, which a server answers with a whole 200.
     */
    #[Test]
    public function a_connection_that_ends_before_any_data_starts_over(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cdn = new FakeCdnProbe([$this->serveFile($gz, until: 0), $this->serveFile($gz)]);

        [$count, $rows, $error] = $this->readThrough($cdn);

        $this->assertNull($error);
        $this->assertSame(range(0, 1999), array_column($rows, 'n'));
        $this->assertSame(2000, $count);
        $this->assertSame([['offset' => 0, 'validator' => null], ['offset' => 0, 'validator' => null]], $cdn->requests);
        self::assertSlept([5_000]);
    }

    /**
     * `If-Range` makes the CDN answer a replaced file with a whole 200.
     * Splicing that onto the rows already imported would mix two datasets.
     */
    #[Test]
    public function it_aborts_when_the_file_was_replaced_before_the_resume(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cut = intdiv(strlen($gz), 2);
        $replaced = gzencode($this->sampleJsonl(2100));
        $cdn = new FakeCdnProbe([
            $this->serveFile($gz, until: $cut),
            $this->serveFile($replaced, headers: ['HTTP/1.1 200 OK', 'Content-Length: '.strlen($replaced), 'ETag: "a-newer-file"']),
        ]);

        [, $rows, $error] = $this->readThrough($cdn);

        $this->assertNotNull($error);
        $this->assertMatchesRegularExpression('/answered HTTP 200 .*replaced/', $error->getMessage());
        $this->assertSame(range(0, count($rows) - 1), array_column($rows, 'n'), 'nothing of the new file reached the callback');
    }

    #[Test]
    public function it_aborts_when_the_resumed_range_starts_somewhere_else(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cut = intdiv(strlen($gz), 2);
        $cdn = new FakeCdnProbe([$this->serveFile($gz, until: $cut), $this->serveRest($gz, skew: -100)]);

        [, , $error] = $this->readThrough($cdn);

        $this->assertNotNull($error);
        $this->assertMatchesRegularExpression('/answered HTTP 206 \(bytes '.($cut - 100).'-/', $error->getMessage());
    }

    /**
     * A 206 from the right byte, but of a file of another size: something
     * between us and Scryfall ignored `If-Range`, and the rest belongs to a
     * different file.
     */
    #[Test]
    public function it_aborts_when_the_resumed_range_is_of_a_file_of_another_size(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cut = intdiv(strlen($gz), 2);
        $cdn = new FakeCdnProbe([$this->serveFile($gz, until: $cut), $this->serveRest($gz, total: strlen($gz) + 500)]);

        [, , $error] = $this->readThrough($cdn);

        $this->assertNotNull($error);
        $this->assertMatchesRegularExpression('/answered HTTP 206 \(bytes '.$cut.'-\d+\/'.(strlen($gz) + 500).'\)/', $error->getMessage());
    }

    #[Test]
    public function it_does_not_resume_without_a_validator_to_tell_the_same_file_apart(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $cdn = new FakeCdnProbe([
            $this->serveFile($gz, until: intdiv(strlen($gz), 2), headers: ['HTTP/1.1 200 OK', 'Content-Length: '.strlen($gz)]),
        ]);

        [, , $error] = $this->readThrough($cdn);

        $this->assertNotNull($error);
        $this->assertMatchesRegularExpression('/without an ETag or Last-Modified date there is no safe way to resume it/', $error->getMessage());
        $this->assertCount(1, $cdn->requests);
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function a_weak_etag_falls_back_to_last_modified_as_the_validator(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $headers = ['HTTP/1.1 200 OK', 'Content-Length: '.strlen($gz), 'ETag: W/"weak"', 'Last-Modified: '.self::LAST_MODIFIED];
        $cdn = new FakeCdnProbe([$this->serveFile($gz, until: intdiv(strlen($gz), 2), headers: $headers), $this->serveRest($gz)]);

        [$count, , $error] = $this->readThrough($cdn);

        $this->assertNull($error);
        $this->assertSame(2000, $count);
        $this->assertSame(self::LAST_MODIFIED, $cdn->requests[1]['validator']);
    }

    /**
     * After a redirect PHP's HTTP wrapper records both responses; the
     * validator must come from the one that carried the file.
     */
    #[Test]
    public function the_validator_comes_from_the_response_after_a_redirect(): void
    {
        $gz = gzencode($this->sampleJsonl(2000));
        $headers = [
            'HTTP/1.1 302 Found', 'Location: https://data.scryfall.io/elsewhere.jsonl.gz', 'ETag: "the-redirect"',
            'HTTP/1.1 200 OK', 'Content-Length: '.strlen($gz), 'Last-Modified: '.self::LAST_MODIFIED,
        ];
        $cdn = new FakeCdnProbe([$this->serveFile($gz, until: intdiv(strlen($gz), 2), headers: $headers), $this->serveRest($gz)]);

        [$count, , $error] = $this->readThrough($cdn);

        $this->assertNull($error);
        $this->assertSame(2000, $count);
        $this->assertSame(self::LAST_MODIFIED, $cdn->requests[1]['validator']);
    }

    /**
     * Every byte the server announced arrived, yet the gzip stream never
     * ended: the file itself is cut short, and asking for more cannot help.
     */
    #[Test]
    public function it_does_not_resume_a_file_that_arrived_whole_but_is_cut_short(): void
    {
        $gz = substr(gzencode($this->sampleJsonl(2000)), 0, -8);
        $cdn = new FakeCdnProbe([$this->serveFile($gz)]);

        [, , $error] = $this->readThrough($cdn);

        $this->assertNotNull($error);
        $this->assertMatchesRegularExpression('/although all '.strlen($gz).' bytes arrived: the file is cut short/', $error->getMessage());
        $this->assertCount(1, $cdn->requests);
        Sleep::assertNeverSlept();
    }
}
