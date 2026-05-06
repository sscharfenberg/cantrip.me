<?php

namespace App\Services;

use App\Contracts\DeckCsvRowMapper;
use App\Enums\DeckImportSource;
use App\Jobs\CleanupTempUploads;
use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DeckCategory;
use App\Models\DefaultCard;
use App\Models\Set;
use App\Models\User;
use App\Services\DeckCsvMappers\ArchidektDeckMapper;
use App\Services\DeckCsvMappers\CantripDeckMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates deck CSV imports.
 *
 * The service is purely additive — it walks the CSV and writes
 * commanders / deck_cards / companion rows to the target deck without
 * ever wiping existing data. Callers are responsible for ensuring the
 * deck is in the right state before invoking import:
 *
 *  - **cantrip** path: the controller creates a brand-new deck so
 *    there is nothing to wipe.
 *  - **archidekt** path: the request rejects targets that already
 *    have deck_cards, so the deck is empty by the time we get here;
 *    commanders + companion (if previously set) are intentionally
 *    preserved because Archidekt's deck export doesn't carry them.
 *
 * The upload endpoint is shared with the collection import — both
 * flows POST to /collection/import/upload, which writes to the `tmp`
 * disk. The {@see CleanupTempUploads} job prunes that disk on
 * a 24h schedule regardless of which subsystem deposited the file, so
 * deck imports inherit the cleanup for free.
 */
class DeckCsvImportService
{
    /**
     * @return array{
     *     imported: int,
     *     commanders: int,
     *     companion: int,
     *     skipped: int,
     *     skipped_rows: array<int, array{row: int, name: string, reason: string}>,
     * }
     *
     * @throws ValidationException
     */
    public static function import(User $user, Deck $deck, string $filename, DeckImportSource $source): array
    {
        $mapper = match ($source) {
            DeckImportSource::Cantrip => new CantripDeckMapper,
            DeckImportSource::Archidekt => new ArchidektDeckMapper,
        };

        $path = Storage::disk('tmp')->path($filename);
        $handle = fopen($path, 'r');

        // Strip BOM if present.
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $delimiter = CsvImportService::detectDelimiter($handle);

        $headerRow = fgetcsv($handle, separator: $delimiter);
        if (! $headerRow) {
            fclose($handle);
            throw ValidationException::withMessages([
                'filename' => [__('validation.custom.file.csv_not_parseable')],
            ]);
        }

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

        $parsed = self::parseAllRows($handle, $mapper, $headerMap, $delimiter);
        fclose($handle);

        // Resolve every printing referenced in the file in one pass.
        $cardMap = self::bulkResolveCards($parsed['rows']);

        $validRows = [];
        $skipped = $parsed['skipped'];
        $skippedRows = $parsed['skipped_rows'];

        foreach ($parsed['rows'] as $row) {
            $cardId = $cardMap[self::cardLookupKey($row['mapped'])] ?? null;
            if (! $cardId) {
                $skipped++;
                $skippedRows[] = [
                    'row' => $row['line'],
                    'name' => $row['mapped']['name'] ?: '?',
                    'reason' => 'card_not_found',
                ];

                continue;
            }
            $row['default_card_id'] = $cardId;
            $validRows[] = $row;
        }

        // Resolve oracle_id for every printing — needed for deck_cards
        // and for commanders/companion. One bulk query keyed by the
        // already-resolved default_card.id.
        $oracleByDefault = $validRows === [] ? [] : DefaultCard::query()
            ->whereIn('id', array_values(array_unique(array_column($validRows, 'default_card_id'))))
            ->pluck('oracle_id', 'id')
            ->all();

        // Pre-resolve user-owned stacks for cantrip-format pivot
        // reattachment. We only attach stacks the importing user owns —
        // pivot rows referencing someone else's stack would fail FK
        // anyway, but skipping silently is friendlier than aborting.
        $ownedStackIds = self::resolveOwnedStackIds($user, $validRows);

        $result = DB::transaction(function () use ($deck, $validRows, $oracleByDefault, $ownedStackIds) {
            // Build a category-name → id map, creating any new ones.
            $categoryMap = self::ensureCategories($deck, $validRows);

            $imported = 0;
            $commanders = 0;
            $companion = 0;

            foreach ($validRows as $row) {
                $mapped = $row['mapped'];
                $defaultCardId = $row['default_card_id'];
                $oracleId = $oracleByDefault[$defaultCardId] ?? null;
                if (! $oracleId) {
                    continue;
                }

                if ($mapped['role'] === 'commander') {
                    DB::table('commanders')->insert([
                        'deck_id' => $deck->id,
                        'oracle_card_id' => $oracleId,
                        'default_card_id' => $defaultCardId,
                        'is_partner' => $mapped['is_partner'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $commanders++;

                    continue;
                }

                if ($mapped['role'] === 'companion') {
                    // First companion row wins; duplicates silently
                    // discarded — a deck can only have one companion.
                    if ($companion > 0) {
                        continue;
                    }
                    $deck->companion_oracle_card_id = $oracleId;
                    $deck->companion_default_card_id = $defaultCardId;
                    $deck->save();
                    $companion++;

                    continue;
                }

                $deckCard = DeckCard::create([
                    'deck_id' => $deck->id,
                    'oracle_card_id' => $oracleId,
                    'default_card_id' => $defaultCardId,
                    'category_id' => $mapped['category'] !== null
                        ? ($categoryMap[$mapped['category']] ?? null)
                        : null,
                    'zone' => $mapped['zone'],
                    'quantity' => $mapped['quantity'],
                ]);
                $imported += (int) $mapped['quantity'];

                // Reattach pivot rows for stacks the importing user
                // owns. Stacks owned by someone else (or that no longer
                // exist) are silently dropped.
                $attachable = array_values(array_intersect($mapped['card_stack_ids'], $ownedStackIds));
                if ($attachable !== []) {
                    $deckCard->cardStacks()->attach($attachable);
                }
            }

            return [
                'imported' => $imported,
                'commanders' => $commanders,
                'companion' => $companion,
            ];
        });

        DeckCardService::recalculateColors($deck);
        $deck->syncHeroImage();

        Storage::disk('tmp')->delete($filename);

        return [
            'imported' => $result['imported'],
            'commanders' => $result['commanders'],
            'companion' => $result['companion'],
            'skipped' => $skipped,
            'skipped_rows' => $skippedRows,
        ];
    }

    /**
     * @param  resource  $handle
     * @param  array<string, int>  $headerMap
     * @return array{rows: array<int, array{line: int, mapped: array}>, skipped: int, skipped_rows: array<int, array{row: int, name: string, reason: string}>}
     */
    private static function parseAllRows($handle, DeckCsvRowMapper $mapper, array $headerMap, string $delimiter): array
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

            // Without a Scryfall ID we need at least set + collector
            // number; with neither, the resolver can't find anything.
            if (! $mapped['scryfall_id'] && ($mapped['set_code'] === '' || $mapped['collector_number'] === '')) {
                $skipped++;
                $skippedRows[] = ['row' => $lineNumber, 'name' => $mapped['name'] ?: '?', 'reason' => 'missing_identifiers'];

                continue;
            }

            $rows[] = ['line' => $lineNumber, 'mapped' => $mapped];
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'skipped_rows' => $skippedRows];
    }

    /**
     * @param  array<int, array{mapped: array}>  $rows
     * @return array<string, string> Lookup key → default_card_id.
     */
    private static function bulkResolveCards(array $rows): array
    {
        $cardMap = [];
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

        if ($scryfallIds) {
            $found = DefaultCard::whereIn('id', array_unique($scryfallIds))->pluck('id')->all();
            foreach ($found as $id) {
                $cardMap["scryfall:{$id}"] = $id;
            }
        }

        if ($fallbackRows) {
            $setCodes = array_unique(array_column($fallbackRows, 'set_code'));
            $setMap = Set::whereIn('code', $setCodes)->pluck('id', 'code')->all();

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

            foreach ($fallbackRows as $mapped) {
                $setId = $setMap[$mapped['set_code']] ?? null;
                if ($setId) {
                    $key = "set:{$setId}:{$mapped['collector_number']}";
                    if (isset($cardMap[$key])) {
                        $cardMap["fallback:{$mapped['set_code']}:{$mapped['collector_number']}"] = $cardMap[$key];
                    }
                }
            }
        }

        return $cardMap;
    }

    /**
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
     * Pre-resolve which of the referenced card_stack_ids the importing
     * user actually owns. Stacks owned by someone else (or deleted) are
     * silently dropped so the import doesn't error mid-flight.
     *
     * @param  array<int, array{mapped: array}>  $rows
     * @return array<int, string>
     */
    private static function resolveOwnedStackIds(User $user, array $rows): array
    {
        $referenced = [];
        foreach ($rows as $row) {
            foreach ($row['mapped']['card_stack_ids'] as $id) {
                $referenced[] = $id;
            }
        }
        $referenced = array_values(array_unique($referenced));
        if ($referenced === []) {
            return [];
        }

        return CardStack::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $referenced)
            ->pluck('id')
            ->all();
    }

    /**
     * Ensure every distinct category name referenced in `card`-role
     * rows exists on the deck and return a name → id map. Rows with
     * a null category contribute nothing.
     *
     * @param  array<int, array{mapped: array}>  $rows
     * @return array<string, string>
     */
    private static function ensureCategories(Deck $deck, array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            if ($row['mapped']['role'] !== 'card') {
                continue;
            }
            $name = $row['mapped']['category'];
            if ($name !== null) {
                $names[$name] = true;
            }
        }
        if ($names === []) {
            return [];
        }

        $existing = DeckCategory::query()
            ->where('deck_id', $deck->id)
            ->whereIn('name', array_keys($names))
            ->pluck('id', 'name')
            ->all();

        $map = $existing;
        foreach (array_keys($names) as $name) {
            if (! isset($map[$name])) {
                $created = DeckCategory::create([
                    'deck_id' => $deck->id,
                    'name' => Str::limit($name, DeckCategory::NAME_MAX, ''),
                ]);
                $map[$name] = $created->id;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $headerRow
     * @return array<string, int> Lowercase header → column index.
     */
    private static function buildHeaderMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $idx => $value) {
            $map[strtolower(trim((string) $value))] = $idx;
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $headerMap
     * @param  resource  $handle
     */
    private static function validateRequiredHeaders(DeckCsvRowMapper $mapper, array $headerMap, $handle): void
    {
        $missing = [];
        foreach ($mapper->requiredHeaders() as $required) {
            if (! array_key_exists($required, $headerMap)) {
                $missing[] = $required;
            }
        }
        if ($missing !== []) {
            fclose($handle);
            throw ValidationException::withMessages([
                'filename' => [__('validation.custom.file.csv_missing_headers', ['headers' => implode(', ', $missing)])],
            ]);
        }
    }

    /**
     * @param  array<int, string>  $rawRow
     * @param  array<string, int>  $headerMap
     * @return array<string, string>
     */
    private static function buildAssociativeRow(array $rawRow, array $headerMap): array
    {
        $assoc = [];
        foreach ($headerMap as $name => $idx) {
            $assoc[$name] = $rawRow[$idx] ?? '';
        }

        return $assoc;
    }

    /**
     * Excel for Mac's "Get Data > From CSV" path occasionally writes a
     * literal `Column1;Column2;…` row before the real header. Mirror
     * the collection importer's tolerance for it.
     *
     * @param  array<int, string>  $row
     */
    private static function isExcelGeneratedRow(array $row): bool
    {
        if (count($row) !== 1) {
            return false;
        }
        $cell = trim((string) ($row[0] ?? ''));

        return $cell !== '' && preg_match('/^Column\d+([;,]Column\d+)+$/i', $cell) === 1;
    }
}
