<?php

namespace App\Services;

use App\Contracts\CsvRowMapper;
use App\Enums\CardCondition;
use App\Enums\CardLanguage;
use App\Enums\Finish;
use App\Enums\ImportSource;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\DefaultCard;
use App\Models\Set;
use App\Models\User;
use App\Services\CsvMappers\ArchidektMapper;
use App\Services\CsvMappers\CantripMapper;
use App\Services\CsvMappers\MoxfieldMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates CSV import processing.
 *
 * Parses an uploaded CSV file using a source-specific mapper, bulk-resolves
 * cards against the database, and upserts card stacks. Designed for files
 * up to 2 MB (~10k rows) processed synchronously.
 */
class CsvImportService
{
    /**
     * Process a CSV import from the tmp disk.
     *
     * Uses bulk queries to avoid per-row DB overhead:
     * 1. Parse all rows and normalize via mapper
     * 2. Bulk-resolve cards (Scryfall IDs + set/collector# fallback)
     * 3. Bulk-fetch existing stacks to detect merges
     * 4. Batch insert new stacks, batch update merged stacks
     *
     * @return array{imported: int, merged: int, skipped: int, skipped_rows: array<array{row: int, name: string, reason: string}>}
     *
     * @throws ValidationException When the file is unparseable or missing required headers.
     */
    public static function import(User $user, string $filename, ImportSource $source, ?string $containerId): array
    {
        $mapper = match ($source) {
            ImportSource::Cantrip => new CantripMapper,
            ImportSource::Moxfield => new MoxfieldMapper,
            ImportSource::Archidekt => new ArchidektMapper,
        };

        $path = Storage::disk('tmp')->path($filename);
        $handle = fopen($path, 'r');

        // Skip UTF-8 BOM if present (written by Excel or cantrip.me export for Excel compatibility).
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Detect delimiter from the first line (comma or semicolon).
        $delimiter = self::detectDelimiter($handle);

        $headerRow = fgetcsv($handle, separator: $delimiter);
        if (! $headerRow) {
            fclose($handle);
            throw ValidationException::withMessages([
                'filename' => [__('validation.custom.file.csv_not_parseable')],
            ]);
        }

        // Excel Mac data import prepends a row like "Column1;Column2;…" as a single field — skip it.
        if (self::isExcelGeneratedRow($headerRow)) {
            $headerRow = fgetcsv($handle, separator: $delimiter);
            if (! $headerRow) {
                fclose($handle);
                throw ValidationException::withMessages([
                    'filename' => [__('validation.custom.file.csv_not_parseable')],
                ]);
            }
        }

        $headerMap = self::buildHeaderMap($headerRow);
        self::validateRequiredHeaders($mapper, $headerMap, $handle);

        // Phase 1: Parse all rows.
        $parsedRows = self::parseAllRows($handle, $mapper, $headerMap, $delimiter);
        fclose($handle);

        // Phase 2: Bulk-resolve cards.
        $cardMap = self::bulkResolveCards($parsedRows['rows']);

        // Phase 3: Match cards to rows, separate valid from skipped.
        $validRows = [];
        $skipped = $parsedRows['skipped'];
        $skippedRows = $parsedRows['skipped_rows'];

        foreach ($parsedRows['rows'] as $row) {
            $lookupKey = self::cardLookupKey($row['mapped']);
            $cardId = $cardMap[$lookupKey] ?? null;

            if (! $cardId) {
                $skipped++;
                $skippedRows[] = [
                    'row' => $row['line'],
                    'name' => $row['mapped']['name'] ?: '?',
                    'reason' => 'card_not_found',
                ];

                continue;
            }

            $row['card_id'] = $cardId;
            $validRows[] = $row;
        }

        // Phase 3b: For cantrip.me imports, resolve per-row container IDs.
        $ownedContainerIds = self::resolveOwnedContainers($user, $validRows);

        // Assign effective container ID per row: CSV container_id (if owned) → form container → null.
        foreach ($validRows as &$row) {
            $csvContainerId = $row['mapped']['container_id'] ?? null;
            $row['container_id'] = ($csvContainerId && isset($ownedContainerIds[$csvContainerId]))
                ? $csvContainerId
                : $containerId;
        }
        unset($row);

        // Phase 4: Bulk upsert stacks.
        $result = self::bulkUpsertStacks($user, $validRows);

        Storage::disk('tmp')->delete($filename);

        return [
            'imported' => $result['imported'],
            'merged' => $result['merged'],
            'skipped' => $skipped,
            'skipped_rows' => $skippedRows,
        ];
    }

    /**
     * Parse all CSV rows via the mapper, collecting valid and skipped rows.
     *
     * @param  resource  $handle
     * @param  array<string, int>  $headerMap
     * @return array{rows: array<array{line: int, mapped: array}>, skipped: int, skipped_rows: array<array{row: int, name: string, reason: string}>}
     */
    private static function parseAllRows($handle, CsvRowMapper $mapper, array $headerMap, string $delimiter = ','): array
    {
        $rows = [];
        $skipped = 0;
        $skippedRows = [];
        $lineNumber = 1;

        while (($rawRow = fgetcsv($handle, separator: $delimiter)) !== false) {
            $lineNumber++;

            if ($rawRow === [null]) {
                continue;
            }

            $row = self::buildAssociativeRow($rawRow, $headerMap);
            $mapped = $mapper->mapRow($row);

            if ($mapped === null) {
                $skipped++;
                $skippedRows[] = ['row' => $lineNumber, 'name' => trim($row['name'] ?? '?'), 'reason' => 'unmappable'];

                continue;
            }

            $skipReason = self::validateMappedRow($mapped);
            if ($skipReason) {
                $skipped++;
                $skippedRows[] = ['row' => $lineNumber, 'name' => $mapped['name'] ?: '?', 'reason' => $skipReason];

                continue;
            }

            $rows[] = ['line' => $lineNumber, 'mapped' => $mapped];
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'skipped_rows' => $skippedRows];
    }

    /**
     * Bulk-resolve DefaultCard IDs from Scryfall IDs and set+collector# fallback.
     *
     * Returns a map of lookup key → default_card_id.
     *
     * @param  array<array{mapped: array}>  $rows
     * @return array<string, string>
     */
    private static function bulkResolveCards(array $rows): array
    {
        $cardMap = [];

        // Collect Scryfall IDs for direct lookup.
        $scryfallIds = [];
        $fallbackRows = [];

        foreach ($rows as $row) {
            $mapped = $row['mapped'];
            if ($mapped['scryfall_id']) {
                $scryfallIds[] = $mapped['scryfall_id'];
            } else {
                $fallbackRows[] = $mapped;
            }
        }

        // Bulk fetch by Scryfall ID.
        if ($scryfallIds) {
            $found = DefaultCard::whereIn('id', array_unique($scryfallIds))
                ->pluck('id')
                ->flip()
                ->all();

            foreach ($found as $id => $ignored) {
                $cardMap["scryfall:{$id}"] = $id;
            }
        }

        // Bulk fetch by set code + collector number.
        if ($fallbackRows) {
            // Resolve all needed set codes → IDs in one query.
            $setCodes = array_unique(array_column($fallbackRows, 'set_code'));
            $setMap = Set::whereIn('code', $setCodes)->pluck('id', 'code')->all();

            // Group by set_id to batch card lookups.
            $bySet = [];
            foreach ($fallbackRows as $mapped) {
                $setId = $setMap[$mapped['set_code']] ?? null;
                if ($setId) {
                    $bySet[$setId][] = $mapped['collector_number'];
                }
            }

            foreach ($bySet as $setId => $collectorNumbers) {
                $cards = DefaultCard::where('set_id', $setId)
                    ->whereIn('collector_number', array_unique($collectorNumbers))
                    ->get(['id', 'collector_number']);

                foreach ($cards as $card) {
                    $cardMap["set:{$setId}:{$card->collector_number}"] = $card->id;
                }
            }

            // Build reverse map from set_code to set_id for key generation.
            foreach ($fallbackRows as $mapped) {
                $setId = $setMap[$mapped['set_code']] ?? null;
                if ($setId) {
                    $key = "set:{$setId}:{$mapped['collector_number']}";
                    // Already populated above, just ensure the alias key works.
                    if (isset($cardMap[$key])) {
                        $cardMap["fallback:{$mapped['set_code']}:{$mapped['collector_number']}"] = $cardMap[$key];
                    }
                }
            }
        }

        return $cardMap;
    }

    /**
     * Build the lookup key for a mapped row matching the cardMap keys.
     *
     * @param  array{scryfall_id: ?string, set_code: string, collector_number: string}  $mapped
     */
    private static function cardLookupKey(array $mapped): string
    {
        if ($mapped['scryfall_id']) {
            return "scryfall:{$mapped['scryfall_id']}";
        }

        return "fallback:{$mapped['set_code']}:{$mapped['collector_number']}";
    }

    /**
     * Bulk upsert card stacks: insert new stacks and increment existing ones.
     *
     * Each row carries its own `container_id` so stacks can span multiple containers.
     *
     * Only unclaimed real-card stacks are merge targets, as in
     * {@see CardStackService::addToCollection}: a stack a deck claims keeps
     * its amount (the deck's business — more would over-claim the deck card),
     * and a proxy stack never absorbs the real cards an import brings. Both
     * are left out of the one query that fetches the candidates, so a row
     * matching only such a stack starts a stack of its own.
     *
     * @param  array<array{card_id: string, container_id: ?string, mapped: array}>  $validRows
     * @return array{imported: int, merged: int}
     */
    private static function bulkUpsertStacks(User $user, array $validRows): array
    {
        if (empty($validRows)) {
            return ['imported' => 0, 'merged' => 0];
        }

        // Collect all distinct container IDs (including null) to fetch existing stacks.
        $containerIds = array_unique(array_column($validRows, 'container_id'));

        // Fetch all mergeable stacks for this user across all relevant containers.
        $existingQuery = CardStack::where('user_id', $user->id)
            ->where('proxy', false)
            ->whereDoesntHave('deckCards');
        $hasNull = in_array(null, $containerIds, true);
        $nonNullIds = array_filter($containerIds, fn ($id) => $id !== null);

        if ($hasNull && $nonNullIds) {
            $existingQuery->where(fn ($q) => $q->whereNull('container_id')->orWhereIn('container_id', $nonNullIds));
        } elseif ($hasNull) {
            $existingQuery->whereNull('container_id');
        } else {
            $existingQuery->whereIn('container_id', $nonNullIds);
        }

        $existingStacks = $existingQuery->get()
            ->keyBy(fn (CardStack $s) => self::stackKey(
                $s->container_id ?? '',
                $s->default_card_id,
                $s->language->value,
                $s->condition?->value,
                $s->finish->value,
            ));

        $inserts = [];
        $merges = []; // stack_id => amount to add
        $imported = 0;
        $merged = 0;
        $now = now();

        foreach ($validRows as $row) {
            $mapped = $row['mapped'];
            $cardId = $row['card_id'];
            $rowContainerId = $row['container_id'];
            $finish = Finish::fromLabel($mapped['finish']);

            $key = self::stackKey($rowContainerId ?? '', $cardId, $mapped['language'], $mapped['condition'], $finish->value);
            $existing = $existingStacks->get($key);

            if ($existing) {
                $merges[$existing->id] = ($merges[$existing->id] ?? 0) + $mapped['amount'];
                $merged++;
            } else {
                // Check if a previous row in this batch already created this stack.
                if (isset($inserts[$key])) {
                    $inserts[$key]['amount'] += $mapped['amount'];
                    $merged++;
                } else {
                    $inserts[$key] = [
                        'id' => Str::uuid()->toString(),
                        'user_id' => $user->id,
                        'default_card_id' => $cardId,
                        'container_id' => $rowContainerId,
                        'amount' => $mapped['amount'],
                        'language' => $mapped['language'],
                        'condition' => $mapped['condition'],
                        'finish' => $finish->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $imported++;
                }
            }
        }

        DB::transaction(function () use ($inserts, $merges) {
            // Batch insert new stacks.
            if ($inserts) {
                foreach (array_chunk(array_values($inserts), 500) as $chunk) {
                    CardStack::insert($chunk);
                }
            }

            // Batch update merged stacks.
            foreach ($merges as $stackId => $amount) {
                CardStack::where('id', $stackId)->increment('amount', $amount);
            }
        });

        return ['imported' => $imported, 'merged' => $merged];
    }

    /**
     * Bulk-verify which container IDs from mapped rows belong to the user.
     *
     * @param  array<array{mapped: array}>  $validRows
     * @return array<string, true> Set of owned container UUIDs.
     */
    private static function resolveOwnedContainers(User $user, array $validRows): array
    {
        $containerIds = [];
        foreach ($validRows as $row) {
            $id = $row['mapped']['container_id'] ?? null;
            if ($id) {
                $containerIds[$id] = true;
            }
        }

        if (empty($containerIds)) {
            return [];
        }

        return Container::where('user_id', $user->id)
            ->whereIn('id', array_keys($containerIds))
            ->pluck('id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Build a composite key for stack deduplication (includes container).
     */
    private static function stackKey(string $containerId, string $cardId, string $language, ?string $condition, int $finish): string
    {
        return "{$containerId}|{$cardId}|{$language}|{$condition}|{$finish}";
    }

    /**
     * Build a lowercase header name → column index map.
     *
     * @param  array<string>  $headerRow
     * @return array<string, int>
     */
    private static function buildHeaderMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $index => $header) {
            $map[strtolower(trim($header))] = $index;
        }

        return $map;
    }

    /**
     * Validate that all required headers are present.
     *
     * @param  array<string, int>  $headerMap
     * @param  resource  $handle  Closed on failure before throwing.
     *
     * @throws ValidationException When required headers are missing.
     */
    private static function validateRequiredHeaders(CsvRowMapper $mapper, array $headerMap, $handle): void
    {
        $missing = array_diff($mapper->requiredHeaders(), array_keys($headerMap));

        if ($missing) {
            fclose($handle);
            throw ValidationException::withMessages([
                'filename' => [__('validation.custom.file.missing_headers', [
                    'headers' => implode(', ', $missing),
                ])],
            ]);
        }
    }

    /**
     * Build an associative row keyed by lowercase header names.
     *
     * @param  array<string>  $rawRow
     * @param  array<string, int>  $headerMap
     * @return array<string, string>
     */
    private static function buildAssociativeRow(array $rawRow, array $headerMap): array
    {
        $row = [];
        foreach ($headerMap as $name => $index) {
            $row[$name] = $rawRow[$index] ?? '';
        }

        return $row;
    }

    /**
     * Validate a mapped row's values. Returns a skip reason or null if valid.
     *
     * @param  array{amount: int, language: string, condition: ?string, finish: string}  $mapped
     */
    private static function validateMappedRow(array $mapped): ?string
    {
        if ($mapped['amount'] < 1) {
            return 'invalid_amount';
        }

        if (! CardLanguage::tryFrom($mapped['language'])) {
            return 'invalid_language';
        }

        if ($mapped['condition'] !== null && ! CardCondition::tryFrom($mapped['condition'])) {
            return 'invalid_condition';
        }

        if (! Finish::fromLabel($mapped['finish'])) {
            return 'invalid_finish';
        }

        return null;
    }

    /**
     * Detect the CSV delimiter by reading the first line and counting occurrences.
     *
     * Rewinds the file handle after detection. Supports comma and semicolon.
     *
     * Accepts either a file handle (seeks back after peeking) or a raw string.
     *
     * @param  resource|string  $input
     */
    public static function detectDelimiter($input): string
    {
        if (is_string($input)) {
            $firstLine = strtok($input, "\n") ?: '';
        } else {
            $pos = ftell($input);
            $firstLine = fgets($input) ?: '';
            fseek($input, $pos);
        }

        $commas = substr_count($firstLine, ',');
        $semicolons = substr_count($firstLine, ';');

        return $semicolons > $commas ? ';' : ',';
    }

    /**
     * Detect an Excel-generated header row (e.g. "Column1;Column2;Column3;…").
     *
     * Excel Mac data import prepends this as a single semicolon-delimited field
     * before the actual CSV data.
     *
     * @param  array<string>  $row
     */
    private static function isExcelGeneratedRow(array $row): bool
    {
        if (count($row) !== 1) {
            return false;
        }

        return (bool) preg_match('/^Column\d+(;Column\d+)*$/', trim($row[0]));
    }
}
