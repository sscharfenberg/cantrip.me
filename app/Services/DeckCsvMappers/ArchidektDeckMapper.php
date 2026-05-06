<?php

namespace App\Services\DeckCsvMappers;

use App\Contracts\DeckCsvRowMapper;

/**
 * Deck-CSV row mapper for Archidekt deck exports.
 *
 * Required columns the user must enable in Archidekt's export UI:
 * Quantity, Name, Edition Code, Collector Number, Category, Scryfall ID
 * (plus "Include header line as first line").
 *
 * Archidekt's deck format doesn't have a dedicated zone or commander
 * column — every row becomes a `card` in the main zone. The category
 * string is preserved verbatim; if a user uses "Commander" as a category
 * name, that becomes a deck_categories row, not a commanders pivot
 * entry. Migrating commanders from an Archidekt import is a manual
 * follow-up via the deck UI.
 */
class ArchidektDeckMapper implements DeckCsvRowMapper
{
    public function requiredHeaders(): array
    {
        return ['quantity', 'name', 'edition code', 'collector number', 'scryfall id'];
    }

    public function mapRow(array $row): ?array
    {
        $quantity = (int) ($row['quantity'] ?? 0);
        if ($quantity < 1) {
            return null;
        }

        $category = trim($row['category'] ?? '');

        return [
            'role' => 'card',
            'scryfall_id' => trim($row['scryfall id'] ?? '') ?: null,
            'set_code' => strtolower(trim($row['edition code'] ?? '')),
            'collector_number' => trim($row['collector number'] ?? ''),
            'name' => trim($row['name'] ?? ''),
            'quantity' => $quantity,
            'zone' => 'main',
            'category' => $category !== '' ? $category : null,
            'is_partner' => false,
            'card_stack_ids' => [],
        ];
    }
}
