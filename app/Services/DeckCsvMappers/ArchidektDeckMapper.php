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
 * Archidekt only carries one structured signal beyond the card list:
 * the Category column. We special-case two values:
 *
 *  - `Commander` (case-insensitive) — the row is treated as a commander
 *    pivot entry, not a deck_card. Partner status is left to the deck
 *    UI (every commander row imports with `is_partner=false`).
 *  - The eight primary card types (Creature, Artifact, Enchantment,
 *    Instant, Sorcery, Land, Planeswalker, Battle) — these mirror the
 *    deck UI's built-in groups, so we drop them on import to avoid
 *    creating redundant custom categories.
 *
 * Anything else lands as a custom category verbatim.
 */
class ArchidektDeckMapper implements DeckCsvRowMapper
{
    /**
     * Categories that map to the built-in deck-page groups. Stored
     * lowercase so the comparison is case-insensitive.
     *
     * @var array<int, string>
     */
    private const DEFAULT_TYPE_CATEGORIES = [
        'creature',
        'planeswalker',
        'battle',
        'artifact',
        'enchantment',
        'instant',
        'sorcery',
        'land',
    ];

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
        $categoryLower = strtolower($category);

        $role = $categoryLower === 'commander' ? 'commander' : 'card';

        $persistedCategory = null;
        if ($role === 'card' && $category !== '' && ! in_array($categoryLower, self::DEFAULT_TYPE_CATEGORIES, true)) {
            $persistedCategory = $category;
        }

        return [
            'role' => $role,
            'scryfall_id' => trim($row['scryfall id'] ?? '') ?: null,
            'set_code' => strtolower(trim($row['edition code'] ?? '')),
            'collector_number' => trim($row['collector number'] ?? ''),
            'name' => trim($row['name'] ?? ''),
            'quantity' => $quantity,
            'zone' => 'main',
            'category' => $persistedCategory,
            'is_partner' => false,
            'card_stack_ids' => [],
        ];
    }
}
