<?php

namespace App\Services\DeckCsvMappers;

use App\Contracts\DeckCsvRowMapper;
use App\Services\DeckCsvExportService;

/**
 * Deck-CSV row mapper for cantrip.me's own export format. Round-trips
 * the columns produced by {@see DeckCsvExportService}:
 * Role, Deck Card ID, Scryfall ID, Name, Edition, Collector Number,
 * Count, Zone, Category, Is Partner, Card Stack ID.
 *
 * `Deck Card ID` is intentionally ignored on import — replace mode
 * wipes existing deck_cards and mints fresh UUIDs, so the source-side
 * id has no destination-side meaning. The column survives in the file
 * for future "patch existing rows" semantics.
 */
class CantripDeckMapper implements DeckCsvRowMapper
{
    public function requiredHeaders(): array
    {
        return ['role', 'scryfall id', 'name', 'count'];
    }

    public function mapRow(array $row): ?array
    {
        $role = strtolower(trim($row['role'] ?? ''));
        // Accept the explicit role names emitted by the post-consolidation
        // export (`partner`, `signature_spell`) alongside the legacy
        // `commander` / `companion` / `card` strings. Any of the three
        // command-zone roles routes through the same downstream logic
        // in DeckCsvImportService.
        if (! in_array($role, ['card', 'commander', 'partner', 'signature_spell', 'companion'], true)) {
            return null;
        }

        $quantity = (int) ($row['count'] ?? 0);
        if ($quantity < 1) {
            return null;
        }

        $zone = strtolower(trim($row['zone'] ?? ''));
        if (! in_array($zone, ['main', 'side'], true)) {
            $zone = 'main';
        }

        $category = trim($row['category'] ?? '');

        $stackIdsRaw = trim($row['card stack id'] ?? '');
        $stackIds = $stackIdsRaw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $stackIdsRaw))));

        return [
            'role' => $role,
            'scryfall_id' => trim($row['scryfall id'] ?? '') ?: null,
            'set_code' => strtolower(trim($row['edition'] ?? '')),
            'collector_number' => trim($row['collector number'] ?? ''),
            'name' => trim($row['name'] ?? ''),
            'quantity' => $quantity,
            'zone' => $zone,
            'category' => $category !== '' ? $category : null,
            'is_partner' => strtolower(trim($row['is partner'] ?? '')) === 'true',
            'card_stack_ids' => $stackIds,
        ];
    }
}
