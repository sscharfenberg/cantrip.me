<?php

namespace App\Services\Scryfall;

use App\Services\Scryfall\Shadow\ShadowTableRegistry;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ScryfallService
{
    /**
     * Read timeout (seconds) for a single read off a bulk stream. Generous
     * because the largest bulk takes minutes to transfer end-to-end; it
     * only needs to cover the gap between two chunks.
     */
    private const STREAM_TIMEOUT = 120;

    /**
     * Bytes pulled off the wire per read while streaming a bulk file.
     */
    private const CHUNK_BYTES = 262144;

    /**
     * Create a pre-configured HTTP client with Scryfall's required headers.
     */
    protected function http(): PendingRequest
    {
        return Http::withHeaders(config('cantrip.scryfall.header'));
    }

    /**
     * Stream a Scryfall bulk file, invoking the callback once per row.
     *
     * Scryfall retired the single-JSON-array bulk files in favour of
     * gzipped JSON Lines (`.jsonl.gz`, served as `content-type:
     * application/gzip`). That broke bulk imports twice over: nothing in
     * the HTTP layer inflates the body for us, and JSON Lines is a stream
     * of root-level documents rather than one parseable document, so the
     * previous `JsonParser::parse()->traverse()` could not read it at all.
     *
     * `.gz` sources are inflated incrementally as they transfer, so this
     * keeps the property the old implementation had: the bulk is never
     * materialized in memory or on disk. Plain sources are read as-is,
     * which lets tests point a bulk_data row at an uncompressed fixture.
     *
     * @param  callable(array<string, mixed>): void  $onRow
     * @return int Number of rows handed to the callback.
     */
    protected function streamJsonl(string $uri, callable $onRow): int
    {
        return str_ends_with($uri, '.gz')
            ? $this->streamGzippedJsonl($uri, $onRow)
            : $this->streamPlainJsonl($uri, $onRow);
    }

    /**
     * Request context carrying Scryfall's required headers. The bulk CDN
     * doesn't enforce them the way the API does, but sending them keeps us
     * identifiable and consistent with {@see ScryfallService::http()}.
     */
    private function streamContext(): mixed
    {
        $headers = [];
        foreach ((array) config('cantrip.scryfall.header') as $name => $value) {
            $headers[] = $name.': '.$value;
        }

        return stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => self::STREAM_TIMEOUT,
            ],
        ]);
    }

    /**
     * Read a gzipped JSONL source, inflating chunk-by-chunk.
     *
     * Inflating by hand rather than through the `compress.zlib://` wrapper
     * buys the one thing that wrapper cannot give us: proof the stream
     * arrived complete. zlib only reports `ZLIB_STREAM_END` once it has
     * consumed gzip's trailing CRC32 and length and validated them against
     * the whole payload, so a connection dropped mid-transfer is always an
     * error — even when it lands exactly on a line boundary, where a
     * partial dataset would otherwise look like a clean end-of-file and
     * import silently. Importing 60% of the cards is worse than importing
     * none, the same reasoning behind the shadow-table flow (see CLAUDE.md).
     *
     * @param  callable(array<string, mixed>): void  $onRow
     */
    private function streamGzippedJsonl(string $uri, callable $onRow): int
    {
        $handle = @fopen($uri, 'r', false, $this->streamContext());
        if ($handle === false) {
            throw new \RuntimeException("failed to open bulk stream '$uri'.");
        }
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);

        $rows = 0;
        $lineNumber = 0;
        $pending = '';
        $complete = false;
        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_BYTES);
                // An empty read means the transfer ended — cleanly at EOF, or
                // early on a timeout or dropped connection. Either way stop
                // reading and let the completeness check below decide which.
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $inflated = @inflate_add($inflate, $chunk);
                if ($inflated === false) {
                    throw new \RuntimeException("gzip inflate failed while reading '$uri' — corrupt bulk stream.");
                }
                $complete = $complete || inflate_get_status($inflate) === ZLIB_STREAM_END;
                $pending .= $inflated;
                while (($newline = strpos($pending, "\n")) !== false) {
                    $line = substr($pending, 0, $newline);
                    $pending = substr($pending, $newline + 1);
                    $lineNumber++;
                    if (trim($line) === '') {
                        continue;
                    }
                    $onRow($this->decodeLine($line, $uri, $lineNumber));
                    $rows++;
                }
            }

            if (! $complete) {
                throw new \RuntimeException(
                    "gzip stream for '$uri' ended early or failed its checksum after $rows rows — "
                    .'aborting rather than importing a partial dataset.'
                );
            }

            // Final line, if the bulk didn't end with a newline.
            if (trim($pending) !== '') {
                $lineNumber++;
                $onRow($this->decodeLine($pending, $uri, $lineNumber));
                $rows++;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Read an uncompressed JSONL source line by line. Used for local
     * fixtures; Scryfall itself only serves `.gz` now.
     *
     * @param  callable(array<string, mixed>): void  $onRow
     */
    private function streamPlainJsonl(string $uri, callable $onRow): int
    {
        $handle = @fopen($uri, 'r', false, $this->streamContext());
        if ($handle === false) {
            throw new \RuntimeException("failed to open bulk stream '$uri'.");
        }

        $rows = 0;
        $lineNumber = 0;
        try {
            while (($raw = fgets($handle)) !== false) {
                $lineNumber++;
                if (trim($raw) === '') {
                    continue;
                }
                $onRow($this->decodeLine($raw, $uri, $lineNumber));
                $rows++;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Decode one JSONL line into an array, treating anything undecodable as
     * a fatal stream problem rather than a row to skip.
     *
     * @return array<string, mixed>
     */
    private function decodeLine(string $line, string $uri, int $lineNumber): array
    {
        $row = json_decode(trim($line), true);
        if (! is_array($row)) {
            throw new \RuntimeException(
                "malformed JSON on line $lineNumber of '$uri' (".json_last_error_msg().'). '
                .'Aborting rather than importing a partial dataset.'
            );
        }

        return $row;
    }

    /**
     * Resolve the live table name to its live or shadow counterpart based
     * on the import mode. Lets each service write the same insert query
     * regardless of whether the orchestrator is rebuilding a shadow set
     * (UpdateEverything) or the command was invoked standalone (live).
     */
    protected function tableName(string $live, bool $shadow): string
    {
        return $shadow ? ShadowTableRegistry::shadow($live) : $live;
    }
}
