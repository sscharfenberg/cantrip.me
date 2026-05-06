<?php

namespace App\Contracts;

/**
 * Contract for source-specific deck CSV row mappers.
 *
 * Each implementation handles one deck-export source format
 * (cantrip.me, Archidekt) and normalises raw CSV values to the canonical
 * shape the deck importer consumes.
 *
 * Distinct from {@see CsvRowMapper} (collection-side): collection rows
 * map to card-stack columns (amount/condition/finish/language); deck
 * rows map to deck_cards columns (quantity/zone/category) plus the
 * commanders + companion role distinctions.
 */
interface DeckCsvRowMapper
{
    /**
     * Column headers required by this mapper (lowercase). Headers in
     * the file that aren't required are tolerated; missing required
     * headers cause the import to abort with a validation error.
     *
     * @return array<string>
     */
    public function requiredHeaders(): array;

    /**
     * Map a raw CSV row to a normalised deck-import shape, or null to
     * skip a row that should not contribute to the deck (zero quantity,
     * unparseable, etc.).
     *
     * @param  array<string, string>  $row  Keyed by lowercase header name.
     * @return array{
     *     role: 'card'|'commander'|'companion',
     *     scryfall_id: ?string,
     *     set_code: string,
     *     collector_number: string,
     *     name: string,
     *     quantity: int,
     *     zone: 'main'|'side',
     *     category: ?string,
     *     is_partner: bool,
     *     card_stack_ids: array<int, string>,
     * }|null
     */
    public function mapRow(array $row): ?array;
}
