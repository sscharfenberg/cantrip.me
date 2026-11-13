<?php

namespace App\Services\Scryfall;

use App\Services\Scryfall\Shadow\ShadowTableRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Throwable;

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
     * Milliseconds to wait before the next attempt when a bulk download
     * could not go on: the first reconnect after a dropped connection goes
     * out at once, these waits follow attempts that brought no data, and the
     * fifth such attempt in a row gives up — under two minutes of waiting.
     * Any data arriving starts the count over. The 2026-11-13 nightly run
     * lost its `default_cards` connection 33 seconds in, at full speed: a
     * dropped connection on Scryfall's CDN, gone by the time anyone looked.
     */
    private const RECONNECT_DELAYS_MS = [5_000, 15_000, 30_000, 60_000];

    /**
     * How every bulk-download error that could leave a partial import ends.
     */
    private const ABORT = ' — aborting rather than importing a partial dataset.';

    /**
     * Seconds to wait for one response from Scryfall's JSON API. The
     * catalogs are small and normally answer in well under a second, but
     * on a slow night they took 8–17 s, and over 30 s — the HTTP client's
     * default — failed the 2026-10-18 nightly run at its first request.
     */
    private const API_TIMEOUT = 60;

    /**
     * Milliseconds to wait before each retry of a Scryfall API request:
     * four attempts across two minutes of waiting, so a slow patch on
     * Scryfall's side can pass before the step gives up and alerts.
     */
    private const API_RETRY_DELAYS_MS = [10_000, 30_000, 90_000];

    /**
     * Create a pre-configured HTTP client with Scryfall's required headers.
     */
    protected function http(): PendingRequest
    {
        return Http::withHeaders(config('cantrip.scryfall.header'));
    }

    /**
     * Client for Scryfall's JSON API — the bulk-data, sets and symbology
     * catalogs and card search. File downloads (card images, set and
     * symbol SVGs) keep {@see self::http()}: they already tolerate a failure
     * per file.
     *
     * Retries what is plausibly transient (no connection, a timeout, 429,
     * a 5xx) and nothing else; a 404 or 422 is an answer. A response that
     * still fails after the last attempt is returned for the caller's own
     * `failed()` check, and a connection that still fails is thrown — so
     * a real outage fails the step and alerts as before.
     */
    protected function api(): PendingRequest
    {
        return $this->http()
            ->timeout(self::API_TIMEOUT)
            ->retry(self::API_RETRY_DELAYS_MS, when: self::isTransient(...), throw: false);
    }

    /**
     * Whether a failed Scryfall API request is worth another attempt.
     */
    private static function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && ($exception->response->serverError() || $exception->response->status() === 429);
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
     *
     * `ignore_errors` hands an HTTP error back as a response with a status
     * to look at, instead of a bare failure to open: a 503 is worth another
     * attempt, a 404 or 416 is an answer.
     *
     * @param  list<string>  $extraHeaders  Header lines sent along with Scryfall's, e.g. `Range` on a resume.
     */
    private function streamContext(array $extraHeaders = []): mixed
    {
        $headers = [];
        foreach ((array) config('cantrip.scryfall.header') as $name => $value) {
            $headers[] = $name.': '.$value;
        }

        return stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [...$headers, ...$extraHeaders],
                'timeout' => self::STREAM_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);
    }

    /**
     * Open a bulk source, from byte `$offset` on when resuming a download
     * that ended early. A resume asks for the rest with `Range` and sends
     * `$validator` — the first response's ETag or Last-Modified date — as
     * `If-Range`, so a file Scryfall replaced in between comes back whole
     * as a 200 instead of being spliced onto the old one as a 206.
     *
     * Returns the handle and the header lines PHP's HTTP wrapper recorded;
     * a local file has none. Every bulk read opens its source here, so tests
     * stand in for the CDN here.
     *
     * @return array{0: resource, 1: list<string>}|null Null when the source could not be opened.
     */
    protected function openBulkSource(string $uri, int $offset = 0, ?string $validator = null): ?array
    {
        $resume = [];
        if ($offset > 0) {
            $resume[] = 'Range: bytes='.$offset.'-';
            if ($validator !== null) {
                $resume[] = 'If-Range: '.$validator;
            }
        }
        $handle = @fopen($uri, 'r', false, $this->streamContext($resume));
        if ($handle === false) {
            return null;
        }
        $headers = stream_get_meta_data($handle)['wrapper_data'] ?? null;

        return [$handle, is_array($headers) ? array_values(array_filter($headers, is_string(...))) : []];
    }

    /**
     * Status and headers of the response that carries the body. PHP's HTTP
     * wrapper records every response it followed a redirect through, in
     * order, so only the lines after the last status line count. A local
     * file has no status.
     *
     * @param  list<string>  $lines
     * @return array{status: int|null, headers: array<string, string>} Header names lower-cased.
     */
    private static function finalResponse(array $lines): array
    {
        $status = null;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'headers' => $headers];
    }

    /**
     * What a resume sends as `If-Range`: a strong ETag, else the
     * Last-Modified date. A weak ETag will not do — `If-Range` only takes
     * strong validators. Null means the download cannot be resumed safely,
     * because nothing would tell the same file from a replaced one.
     *
     * @param  array<string, string>  $headers  Lower-cased names, see {@see self::finalResponse()}.
     */
    private static function resumeValidator(array $headers): ?string
    {
        $etag = $headers['etag'] ?? null;
        if ($etag !== null && $etag !== '' && ! str_starts_with($etag, 'W/')) {
            return $etag;
        }

        return ($headers['last-modified'] ?? '') !== '' ? $headers['last-modified'] : null;
    }

    /**
     * How many bytes the whole source has: the Content-Length of the first
     * response, or the size on disk of a local file.
     *
     * @param  resource  $handle
     * @param  array{status: int|null, headers: array<string, string>}  $response
     */
    private static function sourceSize(mixed $handle, array $response): ?int
    {
        if ($response['status'] === null) {
            $stat = fstat($handle);

            return $stat === false ? null : $stat['size'];
        }
        $length = $response['headers']['content-length'] ?? null;

        return is_numeric($length) ? (int) $length : null;
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
     * The transfer survives a flaky CDN. A connection that ends early is
     * reopened at the byte it reached, and the bytes keep going into the
     * same inflate context, so every row is handed over exactly once and the
     * trailing CRC32 still covers the whole payload — a resume that went
     * wrong fails that check, it cannot import silently. What is plausibly
     * transient — no connection, a 429 or 5xx, a connection that ends
     * before any data — is tried again ({@see self::waitToReconnect()});
     * any other answer that is not what was asked for aborts at once
     * ({@see self::acceptResponse()}).
     *
     * @param  callable(array<string, mixed>): void  $onRow
     */
    private function streamGzippedJsonl(string $uri, callable $onRow): int
    {
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        $handle = null;
        $validator = null;
        $size = null;
        $received = 0;
        $connections = 0;
        $failures = 0;
        $problem = '';
        $rows = 0;
        $lineNumber = 0;
        $pending = '';
        $complete = false;
        try {
            while (true) {
                if ($failures > 0) {
                    $this->waitToReconnect($uri, $received, $rows, $failures, $problem);
                }
                $source = $this->openBulkSource($uri, $received, $received > 0 ? $validator : null);
                if ($source === null) {
                    $failures++;
                    $problem = 'could not connect';

                    continue;
                }
                [$handle, $headerLines] = $source;
                $response = self::finalResponse($headerLines);
                $status = $response['status'];
                if ($status !== null && ($status === 429 || $status >= 500)) {
                    fclose($handle);
                    $failures++;
                    $problem = "HTTP $status";

                    continue;
                }
                $this->acceptResponse($uri, $response, $received, $size, $rows);
                if ($received === 0) {
                    $validator = self::resumeValidator($response['headers']);
                    $size = self::sourceSize($handle, $response);
                }
                if ($connections++ > 0 || $failures > 0) {
                    ScryfallRunStats::$bulkReconnects++;
                }

                $before = $received;
                while (! feof($handle)) {
                    // Silenced because a connection reset is a warning, which
                    // Laravel turns into an exception. An empty read means this
                    // connection is done — at EOF, or early on a timeout or a
                    // dropped connection — and the check below tells which.
                    $chunk = @fread($handle, self::CHUNK_BYTES);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $received += strlen($chunk);
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
                if ($complete) {
                    break;
                }

                $how = stream_get_meta_data($handle)['timed_out'] ? 'timed out' : 'ended';
                fclose($handle);
                $handle = null;
                $endedEarly = "gzip stream for '$uri' $how early after $rows rows ($received bytes)";
                if ($size !== null && $received >= $size) {
                    throw new \RuntimeException("$endedEarly, although all $size bytes arrived: the file is cut short".self::ABORT);
                }
                if ($received > 0 && $validator === null) {
                    throw new \RuntimeException("$endedEarly; without an ETag or Last-Modified date there is no safe way to resume it".self::ABORT);
                }
                if ($received > $before) {
                    $failures = 0;
                    Log::channel('scryfall')->warning("$endedEarly; resuming.");
                } else {
                    $failures++;
                    $problem = "the connection $how before any data";
                }
            }

            // Final line, if the bulk didn't end with a newline.
            if (trim($pending) !== '') {
                $lineNumber++;
                $onRow($this->decodeLine($pending, $uri, $lineNumber));
                $rows++;
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return $rows;
    }

    /**
     * Throw unless a response carries what was asked for: the whole file on
     * a fresh start, and on a resume the rest of the same file — a 206 from
     * the byte reached, of a file the size the first response announced.
     * Transient statuses never get here, so anything else is an answer:
     * a 404 or 416, or a 200 because the file was replaced in between.
     *
     * @param  array{status: int|null, headers: array<string, string>}  $response
     */
    private function acceptResponse(string $uri, array $response, int $received, ?int $size, int $rows): void
    {
        $status = $response['status'];
        if ($received === 0) {
            if ($status === null || $status === 200) {
                return;
            }

            throw new \RuntimeException("failed to open bulk stream '$uri': HTTP $status.");
        }

        $range = $response['headers']['content-range'] ?? '';
        if ($status === 206
            && preg_match('#^bytes (\d+)-\d+/(\d+|\*)$#', $range, $match) === 1
            && (int) $match[1] === $received
            && ($size === null || $match[2] === (string) $size)) {
            return;
        }

        throw new \RuntimeException(
            "gzip stream for '$uri' ended early after $rows rows ($received bytes); asked for the rest, Scryfall answered HTTP "
            .($status ?? '?').($range !== '' ? " ($range)" : '').' — the file was probably replaced in between'.self::ABORT
        );
    }

    /**
     * Wait before trying again after `$failures` attempts in a row brought
     * no data, waiting longer each time ({@see self::RECONNECT_DELAYS_MS});
     * once there is no wait left, give up.
     */
    private function waitToReconnect(string $uri, int $received, int $rows, int $failures, string $problem): void
    {
        $attempts = count(self::RECONNECT_DELAYS_MS) + 1;
        if ($failures >= $attempts) {
            throw new \RuntimeException($received === 0
                ? "failed to open bulk stream '$uri': $problem, $failures attempts in a row."
                : "gzip stream for '$uri' ended early after $rows rows ($received bytes) and could not be resumed: "
                    ."$problem, $failures attempts in a row".self::ABORT);
        }

        $delayMs = self::RECONNECT_DELAYS_MS[$failures - 1];
        Log::channel('scryfall')->warning(
            "bulk download '$uri' at byte $received: $problem; trying again in ".($delayMs / 1000).' s (attempt '.($failures + 1)." of $attempts)."
        );
        Sleep::for($delayMs)->milliseconds();
    }

    /**
     * Read an uncompressed JSONL source line by line. Used for local
     * fixtures; Scryfall itself only serves `.gz` now.
     *
     * @param  callable(array<string, mixed>): void  $onRow
     */
    private function streamPlainJsonl(string $uri, callable $onRow): int
    {
        $source = $this->openBulkSource($uri);
        if ($source === null) {
            throw new \RuntimeException("failed to open bulk stream '$uri'.");
        }
        [$handle, $headerLines] = $source;

        $rows = 0;
        $lineNumber = 0;
        try {
            $status = self::finalResponse($headerLines)['status'];
            if ($status !== null && $status !== 200) {
                throw new \RuntimeException("failed to open bulk stream '$uri': HTTP $status.");
            }
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

    /**
     * Delete a stale cached asset, treating failure as non-fatal.
     *
     * Every caller deletes a *superseded* file — an art crop, card face or
     * set icon whose Scryfall timestamp has moved on. The fresh version is
     * downloaded to a new filename immediately afterwards, so a leftover
     * old file costs disk space and nothing else. Letting Flysystem's
     * `UnableToDeleteFile` escape, by contrast, aborts the whole import
     * step and with it the nightly `scryfall:update` — the outcome is
     * wildly out of proportion to the problem.
     *
     * In practice this fires when the file or its directory is not writable
     * by the running user: the shared asset dirs under `/var/www/mbo-shared`
     * are written by whichever user cron happens to run as, so a changed
     * cron user turns every previously-cached file into an undeletable one.
     * Counted in {@see ScryfallRunStats::$staleFilesUndeletable} so a
     * systemic permission problem shows up in the end-of-run summary
     * instead of only in the log.
     *
     * @param  string  $disk  The filesystem disk name.
     * @param  string  $path  Path on that disk.
     * @return bool Whether the file is gone.
     */
    protected function deleteStaleFile(string $disk, string $path): bool
    {
        try {
            Storage::disk($disk)->delete($path);

            return true;
        } catch (Throwable $e) {
            ScryfallRunStats::$staleFilesUndeletable++;
            Log::channel('scryfall')->warning(
                "could not delete stale file '$path' on disk '$disk', leaving it in place: {$e->getMessage()}"
            );

            return false;
        }
    }
}
